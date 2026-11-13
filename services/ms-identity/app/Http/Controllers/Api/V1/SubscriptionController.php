<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SandboxCheckout;
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

    public function sandboxCharge(Request $request, string $reference, SandboxCheckout $checkout): JsonResponse
    {
        abort_unless($request->user()->id_empresa, 422, 'A company account is required.');
        $data = $request->validate([
            'token_id' => ['required', 'string', 'regex:/^tkn_test_[A-Za-z0-9]+$/D'],
        ]);

        return response()->json(
            $checkout->charge($request->user()->id_empresa, $reference, $data['token_id']),
            202,
        );
    }
}
