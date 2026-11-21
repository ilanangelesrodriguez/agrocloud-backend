<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscriptions\SandboxCheckout;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');

        return response()->json($subscriptions->snapshot($request->user()->id_empresa));
    }

    public function offers(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');

        return response()->json($subscriptions->companyOffers($request->user()->id_empresa));
    }

    public function checkout(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');
        $data = $request->validate(['offer_key' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/D']]);

        return response()->json($subscriptions->checkout($request->user()->id_empresa, $data['offer_key'], $request->user()), 201);
    }

    public function orderStatus(Request $request, string $reference, SandboxCheckout $checkout): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');

        return response()->json($checkout->status($request->user()->id_empresa, $reference));
    }

    public function reconcileOrder(Request $request, string $reference, SandboxCheckout $checkout): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');

        return response()->json($checkout->reconcile($request->user()->id_empresa, $reference));
    }

    public function sandboxCharge(Request $request, string $reference, SandboxCheckout $checkout): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');
        $data = $request->validate([
            ...$this->chargeRules(),
        ]);

        return response()->json(
            $checkout->charge($request->user()->id_empresa, $reference, $data['token_id'], [
                ...$data, 'first_name' => mb_substr((string) $request->user()->nombre, 0, 100),
                'last_name' => mb_substr((string) $request->user()->apellido, 0, 100),
            ]),
            202,
        );
    }

    private function chargeRules(): array
    {
        return ['token_id' => ['required', 'string', 'max:128', 'regex:/^tkn_test_[A-Za-z0-9]+$/D'],
            'device_fingerprint_id' => ['required', 'uuid:4']];
    }

    public function completeAuthentication(Request $request, string $reference, SandboxCheckout $checkout): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');
        $data = $request->validate([
            ...$this->chargeRules(), 'attempt_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/D'],
            'authentication_3ds' => ['required', 'array:eci,xid,cavv,protocolVersion,directoryServerTransactionId'],
            'authentication_3ds.eci' => ['required', 'string', 'regex:/^\d{2}$/D'],
            'authentication_3ds.xid' => ['required', 'string', 'max:256', 'regex:~^[A-Za-z0-9+/=_-]+$~D'],
            'authentication_3ds.cavv' => ['required', 'string', 'max:256', 'regex:~^[A-Za-z0-9+/=_-]+$~D'],
            'authentication_3ds.protocolVersion' => ['required', 'string', 'regex:/^[12]\.\d{1,2}\.\d{1,2}$/D'],
            'authentication_3ds.directoryServerTransactionId' => ['nullable', 'uuid'],
        ]);
        abort_if(str_starts_with($data['authentication_3ds']['protocolVersion'], '2.') && empty($data['authentication_3ds']['directoryServerTransactionId']), 422,
            'Falta el identificador de transacción de autenticación 3DS.');

        return response()->json($checkout->charge($request->user()->id_empresa, $reference, $data['token_id'], [
            ...$data, 'first_name' => mb_substr((string) $request->user()->nombre, 0, 100),
            'last_name' => mb_substr((string) $request->user()->apellido, 0, 100),
        ], true), 202);
    }

    public function cancelAuthentication(Request $request, string $reference, SandboxCheckout $checkout): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');

        return response()->json($checkout->cancelAuthentication($request->user()->id_empresa, $reference));
    }
}
