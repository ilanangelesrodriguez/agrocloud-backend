<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
}
