<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (): JsonResponse {
    return response()->json(['status' => 'ok', 'service' => 'ms-identity']);
})->name('health');

Route::get('/ready', function (): JsonResponse {
    try {
        DB::select('SELECT 1');

        return response()->json(['ready' => true, 'database' => true]);
    } catch (Throwable) {
        return response()->json(['ready' => false, 'database' => false], 503);
    }
})->name('ready');
