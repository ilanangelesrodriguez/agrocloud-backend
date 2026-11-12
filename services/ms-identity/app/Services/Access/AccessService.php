<?php

namespace App\Services\Access;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\SolicitudAcceso;
use App\Models\User;
use App\Services\SunatRucService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessService
{
    public function decide(int $id, string $decision, string $reason, User $actor, bool $manualReview = false): SolicitudAcceso
    {
        abort_unless($actor->isSuperAdmin(), 403);
        $initial = SolicitudAcceso::findOrFail($id);
        $verification = $decision === 'APROBADA' ? app(SunatRucService::class)->verificar($initial->ruc, $initial->razon_social) : null;

        if ($decision === 'APROBADA' && (($verification['resultado'] ?? null) !== 'verificado'
            || ($verification['estado'] ?? null) !== 'ACTIVO' || ($verification['condicion'] ?? null) !== 'HABIDO')) {
            if (! $manualReview) {
                throw ValidationException::withMessages(['revision_manual' => 'La aprobación requiere revisión manual justificada.']);
            }
            $verification['revision_manual'] = ['motivo' => $reason, 'id_usuario' => $actor->getKey(), 'fecha' => now()->toIso8601String()];
        }

        return DB::transaction(function () use ($id, $decision, $reason, $actor, $initial, $verification): SolicitudAcceso {
            $entry = SolicitudAcceso::lockForUpdate()->findOrFail($id);
            abort_if(in_array($entry->estado, ['APROBADA', 'RECHAZADA'], true), 409, 'Request already decided.');
            abort_if($initial->ruc !== $entry->ruc || $initial->razon_social !== $entry->razon_social, 409, 'Request changed during verification.');
            if ($decision === 'APROBADA') {
                if (Empresa::where('ruc', $entry->ruc)->exists() || User::where('correo', $entry->correo)->orWhere('pending_email', $entry->correo)->exists()) {
                    throw ValidationException::withMessages(['request' => 'La empresa o el correo ya estan registrados; no se reasignan cuentas.']);
                }
                $role = Rol::where('codigo', 'ADMIN')->where('estado', true)->firstOrFail();
                $company = Empresa::create([...$entry->only(['ruc', 'razon_social', 'nombre_comercial', 'tipo_empresa', 'telefono', 'correo']), 'sunat_verificacion' => $verification]);
                app(SubscriptionService::class)->activateFreePlan($company->getKey());
                $user = User::create([...$entry->only(['nombre', 'apellido', 'correo', 'telefono']), 'id_empresa' => $company->getKey(),
                    'id_rol' => $role->getKey(), 'password_hash' => Str::random(64), 'estado' => false]);
                $user->forceFill(['invitation_hash' => hash('sha256', Str::random(64))])->save();
                $entry->id_empresa = $company->getKey();
                $entry->id_usuario = $user->getKey();
                $entry->sunat_verificacion = $verification;
                AccountSecurity::record($user, 'ACCESS_APPROVED', actor: $actor);
                app(InvitationService::class)->send($user);
            }
            if ($decision === 'RECHAZADA') {
                AccountSecurity::mailTo($entry->correo, 'ACCESS_REJECTED', 'Resultado de tu solicitud AgroCloud', 'Tu solicitud fue rechazada. Motivo: '.$reason);
            }
            $entry->fill(['estado' => $decision, 'motivo' => $reason, 'revisada_por' => $actor->getKey(),
                'decidida_en' => $decision === 'EN_REVISION' ? null : now(),
                'historial' => [...$entry->historial, ['evento' => $decision, 'motivo' => $reason,
                    'id_usuario' => $actor->getKey(), 'fecha' => now()->toIso8601String()]]])->save();

            return $entry;
        }, 3);
    }
}
