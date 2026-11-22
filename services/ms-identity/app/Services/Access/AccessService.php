<?php

namespace App\Services\Access;

use App\Models\SolicitudAcceso;
use App\Models\User;
use App\Services\SunatRucService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessService
{
    public function decide(
        int $id,
        string $decision,
        string $reason,
        User $actor,
        bool $manualReview = false,
        ?string $verificationReference = null,
    ): SolicitudAcceso {
        abort_unless($actor->isSuperAdmin(), 403);
        $initial = SolicitudAcceso::findOrFail($id);
        $verification = $decision === 'APROBADA' ? app(SunatRucService::class)->verificar($initial->ruc, $initial->razon_social) : null;

        if ($decision === 'APROBADA' && (($verification['resultado'] ?? null) !== 'verificado'
            || ($verification['estado'] ?? null) !== 'ACTIVO' || ($verification['condicion'] ?? null) !== 'HABIDO')) {
            if (! $manualReview) {
                throw ValidationException::withMessages(['revision_manual' => 'La aprobación requiere revisión manual justificada.']);
            }
            if (mb_strlen(trim($reason)) < 20) {
                throw ValidationException::withMessages(['motivo' => 'Describe la justificación de la aprobación manual en al menos 20 caracteres.']);
            }

            $verification['revision_manual'] = [
                'motivo' => $reason,
                'referencia_verificacion' => $verificationReference,
                'id_usuario' => $actor->getKey(),
                'fecha' => now()->toIso8601String(),
            ];
        }

        return DB::transaction(function () use ($id, $decision, $reason, $actor, $initial, $verification): SolicitudAcceso {
            $entry = SolicitudAcceso::lockForUpdate()->findOrFail($id);
            abort_if(in_array($entry->estado, ['APROBADA', 'RECHAZADA'], true), 409, 'Request already decided.');
            abort_if($initial->ruc !== $entry->ruc || $initial->razon_social !== $entry->razon_social, 409, 'Request changed during verification.');
            if ($decision === 'APROBADA') {
                $provisioned = app(CompanyOnboarding::class)->provision(
                    [...$entry->only(['ruc', 'razon_social', 'nombre_comercial', 'tipo_empresa', 'telefono', 'correo']), 'sunat_verificacion' => $verification],
                    $entry->only(['nombre', 'apellido', 'correo', 'telefono']),
                    $actor,
                );
                $company = $provisioned['company'];
                $user = $provisioned['administrator'];
                $entry->id_empresa = $company->getKey();
                $entry->id_usuario = $user->getKey();
                $entry->sunat_verificacion = $verification;
                AccountSecurity::record($user, 'ACCESS_APPROVED', actor: $actor);
            }
            if ($decision === 'RECHAZADA') {
                AccountSecurity::mailTo($entry->correo, 'ACCESS_REJECTED', 'Resultado de tu solicitud AgroCloud', 'Tu solicitud fue rechazada. Motivo: '.$reason.'. Puedes corregir tus datos y presentar una nueva solicitud desde la página de acceso.');
            }
            $entry->fill(['estado' => $decision, 'motivo' => $reason, 'revisada_por' => $actor->getKey(),
                'decidida_en' => $decision === 'EN_REVISION' ? null : now(),
                'historial' => [...$entry->historial, ['evento' => $decision, 'motivo' => $reason,
                    'id_usuario' => $actor->getKey(), 'fecha' => now()->toIso8601String()]]])->save();

            return $entry;
        }, 3);
    }
}
