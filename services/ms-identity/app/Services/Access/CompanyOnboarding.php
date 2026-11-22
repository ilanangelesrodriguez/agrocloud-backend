<?php

namespace App\Services\Access;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\SolicitudAcceso;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyOnboarding
{
    /** @return array{company: Empresa, administrator: User} */
    public function provision(array $companyData, array $administratorData, User $actor): array
    {
        abort_unless($actor->isSuperAdmin(), 403);

        return DB::transaction(function () use ($companyData, $administratorData, $actor): array {
            $administratorData['correo'] = mb_strtolower(trim($administratorData['correo']));
            if (Empresa::where('ruc', $companyData['ruc'])->exists()
                || User::where('correo', $administratorData['correo'])->orWhere('pending_email', $administratorData['correo'])->exists()) {
                throw ValidationException::withMessages(['request' => 'La empresa o el correo ya están registrados; no se reasignan cuentas.']);
            }
            $role = Rol::where('codigo', 'ADMIN')->where('estado', true)->firstOrFail();
            $company = Empresa::create($companyData);
            $user = User::create([
                ...array_intersect_key($administratorData, array_flip(['nombre', 'apellido', 'correo', 'telefono'])),
                'id_empresa' => $company->getKey(), 'id_rol' => $role->getKey(),
                'password_hash' => Str::random(64), 'estado' => false,
            ]);
            app(InvitationService::class)->send($user);
            AccountSecurity::record($user, 'COMPANY_ADMINISTRATOR_PROVISIONED', actor: $actor);

            return ['company' => $company, 'administrator' => $user];
        }, 3);
    }

    public function activate(User $user): void
    {
        if ($user->rol?->codigo === 'ADMIN') {
            app(SubscriptionService::class)->activateFreePlan((int) $user->id_empresa);
        }
    }

    public function nextStep(User $user): array
    {
        $entry = SolicitudAcceso::where('id_usuario', $user->getKey())
            ->where('id_empresa', $user->id_empresa)->where('estado', 'APROBADA')->first();
        $key = $entry?->selected_offer_key;
        $paid = $key && DB::table('identity_billing_orders')->where('company_id', $user->id_empresa)
            ->where('offer_key', $key)->where('status', 'PAID')->exists();
        $confirm = $key && ! $paid;

        return [
            'selected_offer_key' => $key, 'selected_offer_snapshot' => $entry?->selected_offer_snapshot,
            'requires_price_confirmation' => (bool) $confirm,
            'next' => $confirm ? '/settings/subscription?offer='.rawurlencode($key) : '/dashboard',
        ];
    }
}
