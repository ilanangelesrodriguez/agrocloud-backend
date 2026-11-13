<?php

use App\Http\Controllers\Api\V1\AccessRequestController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingOfferController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\InternalController;
use App\Http\Controllers\Api\V1\PublicOnboardingController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/health', fn (): JsonResponse => response()->json(['status' => 'ok', 'service' => 'ms-identity']))->name('health');
Route::get('/ready', function (): JsonResponse {
    try {
        DB::select('SELECT 1');
        $schema = Schema::hasTable('identity_sessions') && Schema::hasTable('rol_permiso');
        $key = @file_get_contents(config('identity.private_key_path'));
        $public = @file_get_contents(config('identity.public_key_path'));
        $privateKey = is_string($key) ? openssl_pkey_get_private($key) : false;
        $details = $privateKey ? openssl_pkey_get_details($privateKey) : false;
        $publicKey = is_string($public) ? openssl_pkey_get_public($public) : false;
        $publicDetails = $publicKey ? openssl_pkey_get_details($publicKey) : false;
        $keys = $details && $publicDetails && isset($details['rsa'], $publicDetails['rsa'])
            && $details['bits'] >= 2048 && $details['key'] === $publicDetails['key'];
        $ready = $schema && $keys;

        return response()->json(['ready' => (bool) $ready, 'database' => true, 'schema' => $schema, 'signing_keys' => (bool) $keys], $ready ? 200 : 503);
    } catch (Throwable) {
        return response()->json(['ready' => false], 503);
    }
})->name('ready');
Route::get('/auth/keys', [AuthController::class, 'keys'])->middleware('throttle:identity-api');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:identity-login');
Route::post('/access-requests', [AccessRequestController::class, 'store'])->middleware('throttle:identity-onboarding');
Route::post('/public/ruc/lookup', [PublicOnboardingController::class, 'lookup'])->middleware('throttle:identity-ruc');
Route::get('/public/offers', [PublicOnboardingController::class, 'offers'])->middleware('throttle:identity-api');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:identity-recovery');
Route::middleware('throttle:identity-public')->group(function (): void {
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/auth/activate', [AuthController::class, 'activate']);
    Route::post('/auth/confirm-email', [AuthController::class, 'confirmEmailChange']);
});
Route::middleware(['identity.auth', 'throttle:identity-api'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/auth/change-email', [AuthController::class, 'requestEmailChange']);
    Route::get('/companies', [CompanyController::class, 'index'])->middleware('identity.permission:empresa.ver');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->whereNumber('company')->middleware('identity.permission:empresa.ver');
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::patch('/companies/{company}', [CompanyController::class, 'update'])->whereNumber('company')->middleware('identity.permission:empresa.editar');
    Route::get('/users', [UserController::class, 'index'])->middleware('identity.permission:usuarios.ver');
    Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user')->middleware('identity.permission:usuarios.ver');
    Route::post('/users', [UserController::class, 'store'])->middleware('identity.permission:usuarios.crear');
    Route::patch('/users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('identity.permission:usuarios.editar');
    Route::post('/users/{user}/invitation', [UserController::class, 'invite'])->whereNumber('user')->middleware('identity.permission:usuarios.editar');
    Route::get('/users/{user}/security', [UserController::class, 'audit'])->whereNumber('user')->middleware('identity.permission:usuarios.editar');
    Route::get('/roles', [InternalController::class, 'roles'])->middleware('identity.permission:usuarios.ver');
    Route::get('/permissions', [InternalController::class, 'permissions']);
    Route::get('/access-requests', [AccessRequestController::class, 'index']);
    Route::post('/access-requests/{accessRequest}/decision', [AccessRequestController::class, 'decide'])->whereNumber('accessRequest');
    Route::get('/billing-offers', [BillingOfferController::class, 'index']);
    Route::post('/billing-offers', [BillingOfferController::class, 'store']);
    Route::get('/billing-offers/{offer}', [BillingOfferController::class, 'show'])->where('offer', '[A-Za-z0-9_-]{1,60}');
    Route::patch('/billing-offers/{offer}', [BillingOfferController::class, 'update'])->where('offer', '[A-Za-z0-9_-]{1,60}');
    Route::get('/subscription/offers', [SubscriptionController::class, 'offers'])->middleware('identity.permission:suscripciones.ver');
    Route::get('/subscription', [SubscriptionController::class, 'show'])->middleware('identity.permission:suscripciones.ver');
    Route::post('/subscription/orders', [SubscriptionController::class, 'checkout'])->middleware('identity.permission:suscripciones.gestionar');
    Route::get('/subscription/orders/{reference}', [SubscriptionController::class, 'orderStatus'])->middleware('identity.permission:suscripciones.ver');
    Route::post('/subscription/orders/{reference}/charge', [SubscriptionController::class, 'sandboxCharge'])->middleware('identity.permission:suscripciones.gestionar');
});
Route::prefix('internal')->middleware('throttle:identity-internal')->group(function (): void {
    Route::post('/billing/events', [InternalController::class, 'payment']);
    Route::middleware('identity.service')->group(function (): void {
        Route::post('/auth/introspect', [InternalController::class, 'introspect'])->middleware('identity.auth');
        Route::get('/companies/{company}/recipients', [InternalController::class, 'recipients'])->whereNumber('company');
        Route::get('/companies/{company}/entitlements', [InternalController::class, 'entitlement'])->whereNumber('company');
        Route::post('/companies/{company}/usage', [InternalController::class, 'consume'])->whereNumber('company');
    });
});
