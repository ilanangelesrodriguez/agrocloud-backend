<?php

namespace App\Services\Subscriptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

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
        ];
    }

    public function charge(int $company, string $reference, string $tokenId): array
    {
        abort_unless(config('identity.billing.sandbox_checkout_enabled') === true, 403,
            'El cobro de sandbox no está habilitado.');
        abort_unless((bool) preg_match('/^tkn_test_[A-Za-z0-9]+$/D', $tokenId), 422,
            'Solo se aceptan tokens Culqi de prueba.');

        $order = $this->ownedOrder($company, $reference);
        abort_unless($order->status === 'PENDING', 409,
            'La orden ya no está pendiente. Comprueba su estado antes de continuar.');

        $url = rtrim((string) config('identity.billing.url'), '/');
        $key = (string) config('identity.billing.api_key');
        abort_unless(strlen($key) >= 32 && filter_var($url, FILTER_VALIDATE_URL)
            && (str_starts_with($url, 'https://') || app()->environment('local', 'testing')),
            503, 'La conexión segura con Billing no está configurada.');

        try {
            $response = app(BillingHttpClient::class)->request($url, $key)->timeout(25)
                ->post($url.'/api/v1/payments/charges', [
                    'externalReference' => $order->reference,
                    'tokenId' => $tokenId,
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

        return [
            'reference' => $order->reference,
            'status' => 'PENDING',
            'message' => 'Billing recibió el pago; esperando confirmación firmada en Identity.',
        ];
    }
}
