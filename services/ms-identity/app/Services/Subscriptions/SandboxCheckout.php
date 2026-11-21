<?php

namespace App\Services\Subscriptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Company-scoped, sandbox-only bridge to private ms-billing.
 * Identity remains the source of truth for PAID/entitlements; this endpoint does
 * not activate a subscription based on the browser or on Billing's initial reply.
 */
final class SandboxCheckout
{
    private function ownedOrder(int $company, string $reference): object
    {
        abort_unless((bool) preg_match('/^AGRO-[0-9A-HJKMNP-TV-Z]{26}$/D', $reference), 404);
        $order = DB::table('identity_billing_orders')
            ->where('company_id', $company)
            ->where('reference', $reference)
            ->first();
        abort_unless($order, 404, 'Order not found for this company.');

        return $order;
    }

    public function status(int $company, string $reference): array
    {
        $order = $this->ownedOrder($company, $reference);

        return [
            'reference' => $order->reference,
            'offer_key' => $order->offer_key,
            'kind' => $order->kind,
            'amount_cents' => (int) $order->amount_cents,
            'currency' => $order->currency,
            // Only the signed Billing -> Identity event can move this to PAID.
            'status' => $order->status,
            'payment_state' => $this->paymentState($order),
        ];
    }

    /** @return array{string, string} */
    private function billingConnection(): array
    {
        $url = rtrim((string) config('identity.billing.url'), '/');
        $key = (string) config('identity.billing.api_key');
        abort_unless(strlen($key) >= 32 && filter_var($url, FILTER_VALIDATE_URL)
            && (str_starts_with($url, 'https://') || app()->environment('local', 'testing')),
            503, 'La conexión segura con Billing no está configurada.');

        return [$url, $key];
    }

    private function paymentState(object $order): string
    {
        if ($order->status === 'PAID') {
            return 'CONFIRMED';
        }
        if ($order->status !== 'PENDING' || config('identity.billing.sandbox_checkout_enabled') !== true) {
            return 'UNAVAILABLE';
        }

        try {
            [$url, $key] = $this->billingConnection();
            $response = app(BillingHttpClient::class)->request($url, $key)->timeout(5)
                ->get($url.'/api/v1/payments/'.$order->reference);
        } catch (ConnectionException|HttpException) {
            return 'UNAVAILABLE';
        }

        $payment = $response->json('data');
        if (! $response->ok() || ! is_array($payment) || ($payment['orderReference'] ?? null) !== $order->reference
            || ! array_key_exists('lastAttempt', $payment)) {
            return 'UNAVAILABLE';
        }

        return match ($payment['status'] ?? null) {
            'PAID' => 'AWAITING_CONFIRMATION',
            'PROCESSING' => 'PROCESSING',
            'REQUIRES_3DS' => 'REQUIRES_3DS',
            'UNKNOWN' => 'RECONCILIATION_REQUIRED',
            'PENDING' => match ($payment['lastAttempt']) {
                null => 'READY',
                'CANCELLED' => 'READY',
                'DECLINED' => 'DECLINED',
                default => 'RECONCILIATION_REQUIRED',
            },
            default => 'UNAVAILABLE',
        };
    }

    public function reconcile(int $company, string $reference): array
    {
        abort_unless(config('identity.billing.sandbox_checkout_enabled') === true, 403);
        $order = $this->ownedOrder($company, $reference);
        if ($order->status === 'PAID') {
            return $this->status($company, $reference);
        }
        abort_unless($order->status === 'PENDING', 409);
        [$url, $key] = $this->billingConnection();
        try {
            $response = app(BillingHttpClient::class)->request($url, $key)->timeout(15)
                ->post($url.'/api/v1/payments/'.$order->reference.'/reconcile');
        } catch (ConnectionException) {
            abort(503, 'No se pudo consultar la conciliación. La orden conserva su estado.');
        }
        abort_unless($response->successful(), 502, 'Billing no pudo verificar la operación.');

        return $this->status($company, $reference);
    }

    public function charge(int $company, string $reference, string $tokenId, array $authentication = [], bool $complete = false): array
    {
        abort_unless(config('identity.billing.sandbox_checkout_enabled') === true, 403,
            'El cobro de sandbox no está habilitado.');
        abort_unless((bool) preg_match('/^tkn_test_[A-Za-z0-9]+$/D', $tokenId), 422,
            'Solo se aceptan tokens Culqi de prueba.');

        $order = $this->ownedOrder($company, $reference);
        abort_unless($order->status === 'PENDING', 409,
            'La orden ya no está pendiente. Comprueba su estado antes de continuar.');

        [$url, $key] = $this->billingConnection();

        try {
            $response = app(BillingHttpClient::class)->request($url, $key)->timeout(40)
                ->post($url.'/api/v1/payments/charges'.($complete ? '/complete-3ds' : ''), [
                    'externalReference' => $order->reference,
                    'tokenId' => $tokenId,
                    'deviceFingerprintId' => $authentication['device_fingerprint_id'],
                    'firstName' => $authentication['first_name'] ?? '',
                    'lastName' => $authentication['last_name'] ?? '',
                    ...($complete ? ['attemptId' => $authentication['attempt_id'], 'authentication3DS' => $authentication['authentication_3ds']] : []),
                ]);
        } catch (ConnectionException) {
            abort(503, 'No se pudo consultar Billing; revisa el estado antes de reintentar.');
        }

        if ($response->status() === 422) {
            abort(422, 'El cargo fue rechazado. Verifica la información o utiliza otra tarjeta de prueba.');
        }
        if ($response->status() === 409) {
            abort(409, 'Resultado pendiente o incierto. No vuelvas a cobrar; solicita conciliación.');
        }
        abort_unless($response->successful(), 502,
            'No se confirmó el resultado del cargo. Comprueba el estado antes de reintentar.');

        $payment = $response->json('data');
        abort_unless(is_array($payment) && ($payment['orderReference'] ?? null) === $order->reference, 502, 'Respuesta de pago no verificable.');
        if (($payment['status'] ?? null) === 'REQUIRES_3DS') {
            abort_unless(is_string($payment['attemptId'] ?? null) && preg_match('/^[a-f0-9]{24}$/D', $payment['attemptId'])
                && is_string($payment['authenticationExpiresAt'] ?? null), 502, 'Respuesta de autenticación no verificable.');

            return ['reference' => $order->reference, 'status' => 'REQUIRES_3DS', 'attempt_id' => $payment['attemptId'],
                'authentication_expires_at' => $payment['authenticationExpiresAt'],
                'message' => 'Tu banco requiere autenticación 3DS para continuar con este pago.'];
        }
        abort_unless(($payment['status'] ?? null) === 'PAID', 502, 'No se confirmó el resultado del cargo.');

        return [
            'reference' => $order->reference,
            'status' => 'PENDING',
            'message' => 'Billing recibió el pago; esperando confirmación firmada en Identity.',
        ];
    }

    public function cancelAuthentication(int $company, string $reference): array
    {
        abort_unless(config('identity.billing.sandbox_checkout_enabled') === true, 403);
        $order = $this->ownedOrder($company, $reference);
        abort_unless($order->status === 'PENDING', 409);
        [$url, $key] = $this->billingConnection();
        try {
            $response = app(BillingHttpClient::class)->request($url, $key)->timeout(15)
                ->post($url.'/api/v1/payments/'.$order->reference.'/cancel-authentication');
        } catch (ConnectionException) {
            abort(503, 'No se pudo cancelar la autenticación. Consulta el estado antes de continuar.');
        }
        abort_unless($response->successful(), 409, 'La autenticación ya no está pendiente. Consulta el pago antes de continuar.');

        return $this->status($company, $reference);
    }
}
