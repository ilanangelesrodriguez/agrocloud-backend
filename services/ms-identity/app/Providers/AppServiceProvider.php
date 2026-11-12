<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('identity-login', fn (Request $request): array => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('account:'.hash('sha256', mb_strtolower(trim(is_string($request->input('correo')) ? $request->input('correo') : ''))).':'.$request->ip()),
        ]);
        RateLimiter::for('identity-public', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('identity-onboarding', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('identity-recovery', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('identity-ruc', function (Request $request): array {
            $ruc = $request->input('ruc');
            $key = is_string($ruc) && preg_match('/^[0-9]{11}$/D', $ruc) ? $ruc : 'invalid';

            return [
                Limit::perMinute(15)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('ruc:'.$key),
            ];
        });
        RateLimiter::for('identity-api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->getKey() ?? $request->ip()));
        RateLimiter::for('identity-internal', fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));
    }
}
