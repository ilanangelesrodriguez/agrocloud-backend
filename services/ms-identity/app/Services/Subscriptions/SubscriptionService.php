<?php

namespace App\Services\Subscriptions;

use App\Models\Empresa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function activateFreePlan(int $company): void
    {
        DB::transaction(function () use ($company): void {
            Empresa::lockForUpdate()->findOrFail($company);
            if (DB::table('suscripciones')->where('id_empresa', $company)->exists()) {
                return;
            }

            $duration = filter_var(config('identity.free_plan.duration_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
            abort_if($duration === false, 503, 'Configure the free plan duration.');
            $limits = [];
            foreach (['usuarios', 'lotes', 'analisis_ia'] as $resource) {
                $limit = filter_var(config('identity.free_plan.limits.'.$resource), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => $resource === 'usuarios' ? 1 : 0, 'max_range' => 1000000000],
                ]);
                abort_if($limit === false, 503, 'Configure all free plan quotas.');
                $limits[$resource] = $limit;
            }

            $now = now();
            DB::table('suscripciones')->insert([
                'id_empresa' => $company, 'plan' => 'GRATUITO', 'estado' => 'ACTIVA',
                'inicia_en' => $now, 'vence_en' => $now->copy()->addDays($duration),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($limits as $resource => $limit) {
                DB::table('derechos_acceso')->insert([
                    'id_empresa' => $company, 'recurso' => $resource, 'limite' => $limit, 'consumido' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    public function publicOffers(): array
    {
        $result = [];
        $today = CarbonImmutable::now('UTC')->toDateString();
        foreach (config('identity.billing.offers', []) as $key => $configured) {
            if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $key)
                || ($configured['kind'] ?? null) !== 'PLAN' || ($configured['public'] ?? false) !== true
                || ($configured['plan'] ?? null) === 'GRATUITO') {
                continue;
            }
            $offer = $this->offer($key);
            if (! $this->offerIsCurrent($offer, $today)) {
                continue;
            }

            $result[] = [
                'key' => $key, 'name' => $offer['plan'], 'currency' => 'PEN',
                'amount_cents' => $offer['amount_cents'], 'duration_days' => $offer['duration_days'],
                'limits' => array_intersect_key($offer['limits'], array_flip(['usuarios', 'lotes', 'analisis_ia'])),
                'available' => true,
                ...array_intersect_key($offer, array_flip(['description', 'valid_from', 'valid_until'])),
            ];
        }

        return $result;
    }

    public function snapshot(int $company): array
    {
        $subscription = DB::table('suscripciones')->where('id_empresa', $company)->first();
        $rights = DB::table('derechos_acceso')->where('id_empresa', $company)->get();
        foreach ($rights as $right) {
            if ($right->recurso === 'usuarios') {
                $right->consumido = User::where('id_empresa', $company)->count();
            }
        }

        return ['subscription' => $subscription, 'active' => $subscription && $subscription->estado === 'ACTIVA'
            && CarbonImmutable::parse($subscription->vence_en)->isFuture(), 'rights' => $rights];
    }

    private function active(int $company): object
    {
        $subscription = DB::table('suscripciones')->where('id_empresa', $company)->first();
        abort_unless($subscription && $subscription->estado === 'ACTIVA' && CarbonImmutable::parse($subscription->vence_en)->isFuture(), 403, 'An active subscription is required.');

        return $subscription;
    }

    public function ensureUserCapacity(int $company, int $currentCount): void
    {
        $this->active($company);
        $right = DB::table('derechos_acceso')->where('id_empresa', $company)->where('recurso', 'usuarios')->first();
        abort_unless($right && $currentCount < $right->limite, 403, 'User quota exhausted.');
    }

    private function offer(string $key): array
    {
        $offer = config('identity.billing.offers.'.$key);
        if (! is_array($offer)) {
            throw ValidationException::withMessages(['offer_key' => 'Oferta no configurada.']);
        }
        $kind = $offer['kind'] ?? null;
        abort_unless(in_array($kind, ['PLAN', 'RENEWAL', 'USER_ADDON', 'AI_ADDON', 'LOT_ADDON'], true)
            && is_int($offer['amount_cents'] ?? null) && $offer['amount_cents'] >= 100 && $offer['amount_cents'] <= 999900, 503, 'Invalid configured offer.');
        if (in_array($kind, ['PLAN', 'RENEWAL'], true)) {
            abort_unless(is_string($offer['plan'] ?? null) && trim($offer['plan']) !== '' && strlen($offer['plan']) <= 100
                && is_int($offer['duration_days'] ?? null) && $offer['duration_days'] >= 1 && $offer['duration_days'] <= 366, 503, 'Invalid plan configuration.');
            foreach (['usuarios', 'lotes', 'analisis_ia'] as $resource) {
                abort_unless(is_int($offer['limits'][$resource] ?? null) && $offer['limits'][$resource] >= ($resource === 'usuarios' ? 1 : 0) && $offer['limits'][$resource] <= 1000000000, 503, 'Configure every plan quota.');
            }
        } else {
            abort_unless(is_int($offer['quantity'] ?? null) && $offer['quantity'] > 0 && $offer['quantity'] <= 1000000, 503, 'Invalid addon quantity.');
        }

        foreach (['valid_from', 'valid_until'] as $field) {
            if (! array_key_exists($field, $offer)) {
                continue;
            }
            $date = $offer[$field];
            abort_unless(is_string($date) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]), 503, 'Invalid offer date.');
        }
        abort_if(isset($offer['valid_from'], $offer['valid_until']) && $offer['valid_from'] >= $offer['valid_until'], 503, 'Invalid offer window.');
        if (array_key_exists('description', $offer)) {
            abort_unless(is_string($offer['description']) && trim($offer['description']) !== ''
                && mb_strlen($offer['description']) <= 500, 503, 'Invalid offer description.');
        }

        return $offer;
    }

    private function offerIsCurrent(array $offer, string $today): bool
    {
        return (! isset($offer['valid_from']) || $offer['valid_from'] <= $today)
            && (! isset($offer['valid_until']) || $today < $offer['valid_until']);
    }

    public function checkout(int $company, string $offerKey, User $actor): array
    {
        $offer = $this->offer($offerKey);
        $order = DB::transaction(function () use ($company, $offerKey, $offer, $actor): object {
            Empresa::where('estado', true)->lockForUpdate()->findOrFail($company);
            $existing = DB::table('identity_billing_orders')->where('company_id', $company)->where('status', 'PENDING')->first();
            if ($existing) {
                abort_unless($existing->offer_key === $offerKey, 409, 'Another checkout is pending for this company.');

                return $existing;
            }
            if (! $this->offerIsCurrent($offer, CarbonImmutable::now('UTC')->toDateString())) {
                throw ValidationException::withMessages(['offer_key' => 'La oferta no está vigente.']);
            }
            $subscription = DB::table('suscripciones')->where('id_empresa', $company)->first();
            $active = $subscription && $subscription->estado === 'ACTIVA' && CarbonImmutable::parse($subscription->vence_en)->isFuture();
            if ($offer['kind'] === 'PLAN') {
                abort_if($active && $subscription->plan !== 'GRATUITO', 409, 'Use renewal for an active subscription.');
            } elseif ($offer['kind'] === 'RENEWAL') {
                abort_unless($subscription && $subscription->plan === $offer['plan'], 422, 'Renewal must match the existing plan.');
            } else {
                $this->active($company);
            }
            $reference = 'AGRO-'.Str::ulid();
            DB::table('identity_billing_orders')->insert([
                'reference' => $reference, 'company_id' => $company, 'offer_key' => $offerKey, 'customer_email' => $actor->correo,
                'kind' => $offer['kind'], 'amount_cents' => $offer['amount_cents'], 'currency' => 'PEN',
                'offer' => json_encode($offer, JSON_THROW_ON_ERROR), 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('identity_billing_orders')->where('reference', $reference)->first();
        }, 3);
        $url = rtrim((string) config('identity.billing.url'), '/');
        $key = (string) config('identity.billing.api_key');
        abort_unless(strlen($key) >= 32 && filter_var($url, FILTER_VALIDATE_URL)
            && (str_starts_with($url, 'https://') || app()->environment('local', 'testing')), 503, 'Configure Billing connection.');
        try {
            $response = Http::withHeaders(['X-API-Key' => $key])->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(15)
                ->post($url.'/api/v1/orders', ['externalReference' => $order->reference, 'companyId' => $company,
                    'kind' => $order->kind, 'amountCents' => $order->amount_cents, 'currency' => $order->currency,
                    'customerEmail' => $order->customer_email, 'description' => 'AgroCloud '.$order->offer_key]);
        } catch (ConnectionException) {
            abort(503, 'Billing unavailable; retry the same offer to reuse the pending reference.');
        }
        abort_unless($response->successful(), 502, 'Billing rejected the order; the local reference remains pending.');

        return ['reference' => $order->reference, 'company_id' => $company, 'kind' => $order->kind,
            'amount_cents' => $order->amount_cents, 'currency' => $order->currency];
    }

    public function payment(array $event, string $bodyHash): bool
    {
        return DB::transaction(function () use ($event, $bodyHash): bool {
            Empresa::lockForUpdate()->findOrFail($event['company_id']);
            $order = DB::table('identity_billing_orders')->where('reference', $event['order_reference'])->where('company_id', $event['company_id'])->lockForUpdate()->first();
            abort_unless($order, 422, 'Unknown order; no entitlements were granted.');
            $processed = DB::table('identity_processed_events')->where('event_id', $event['event_id'])->first();
            if ($processed) {
                abort_unless(hash_equals($processed->payload_hash, $bodyHash), 409, 'Event ID reused with another payload.');

                return false;
            }
            abort_unless($order->status === 'PENDING' && $order->amount_cents === $event['amount_cents']
                && $order->currency === $event['currency'] && $order->kind === $event['kind'], 409, 'Payment does not match the expected order.');
            abort_if(DB::table('identity_billing_orders')->where('provider_charge_id', $event['provider_charge_id'])->exists(), 409, 'Charge already applied.');
            $offer = json_decode($order->offer, true, 512, JSON_THROW_ON_ERROR);
            if (in_array($order->kind, ['PLAN', 'RENEWAL'], true)) {
                $old = DB::table('suscripciones')->where('id_empresa', $order->company_id)->first();
                $active = $old && $old->estado === 'ACTIVA' && CarbonImmutable::parse($old->vence_en)->isFuture();
                abort_if($order->kind === 'PLAN' && $active && $old->plan !== 'GRATUITO', 409, 'A plan is already active.');
                abort_if($order->kind === 'RENEWAL' && (! $old || $old->plan !== $offer['plan']), 409, 'Subscription changed since checkout.');
                $base = $active && $order->kind === 'RENEWAL' ? CarbonImmutable::parse($old->vence_en) : CarbonImmutable::now();
                DB::table('suscripciones')->updateOrInsert(['id_empresa' => $order->company_id], [
                    'plan' => $offer['plan'], 'estado' => 'ACTIVA', 'inicia_en' => $active && $order->kind === 'RENEWAL' ? $old->inicia_en : now(),
                    'vence_en' => $base->addDays($offer['duration_days']), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now(),
                ]);
                foreach ($offer['limits'] as $resource => $limit) {
                    if (! in_array($resource, ['usuarios', 'lotes', 'analisis_ia'], true)) {
                        continue;
                    }
                    $current = DB::table('derechos_acceso')->where('id_empresa', $order->company_id)->where('recurso', $resource)->first();
                    $carry = $active && $order->kind === 'RENEWAL' && $resource !== 'usuarios';
                    DB::table('derechos_acceso')->updateOrInsert(['id_empresa' => $order->company_id, 'recurso' => $resource], [
                        'limite' => $carry ? ($current?->limite ?? 0) + $limit : $limit,
                        'consumido' => $carry ? ($current?->consumido ?? 0) : 0,
                        'created_at' => $current?->created_at ?? now(), 'updated_at' => now(),
                    ]);
                }
            } else {
                $this->active($order->company_id);
                $resource = match ($order->kind) {
                    'USER_ADDON' => 'usuarios', 'AI_ADDON' => 'analisis_ia', 'LOT_ADDON' => 'lotes'
                };
                DB::table('derechos_acceso')->insertOrIgnore(['id_empresa' => $order->company_id, 'recurso' => $resource,
                    'limite' => 0, 'consumido' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('derechos_acceso')->where('id_empresa', $order->company_id)->where('recurso', $resource)->increment('limite', $offer['quantity'], ['updated_at' => now()]);
            }
            DB::table('identity_billing_orders')->where('reference', $order->reference)->update([
                'status' => 'PAID', 'provider_charge_id' => $event['provider_charge_id'], 'paid_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('identity_processed_events')->insert(['event_id' => $event['event_id'], 'payload_hash' => $bodyHash, 'processed_at' => now()]);

            return true;
        }, 3);
    }

    public function consume(int $company, string $requestId, string $resource, int $quantity): array
    {
        return DB::transaction(function () use ($company, $requestId, $resource, $quantity): array {
            Empresa::where('estado', true)->lockForUpdate()->findOrFail($company);
            $old = DB::table('identity_usage_requests')->where('id_empresa', $company)->where('request_id', $requestId)->first();
            if ($old) {
                abort_unless($old->recurso === $resource && $old->cantidad === $quantity, 409, 'Usage ID reused with different parameters.');

                return ['consumed' => true, 'duplicate' => true];
            }
            $this->active($company);
            $right = DB::table('derechos_acceso')->where('id_empresa', $company)->where('recurso', $resource)->lockForUpdate()->first();
            abort_unless($right && $right->limite - $right->consumido >= $quantity, 403, 'Quota exhausted.');
            DB::table('derechos_acceso')->where('id', $right->id)->increment('consumido', $quantity, ['updated_at' => now()]);
            DB::table('identity_usage_requests')->insert(['id_empresa' => $company, 'request_id' => $requestId, 'recurso' => $resource, 'cantidad' => $quantity, 'created_at' => now()]);

            return ['consumed' => true, 'duplicate' => false];
        }, 3);
    }
}
