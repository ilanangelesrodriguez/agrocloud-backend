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
            Limit::perMinute(20)->by('ip:'.$this->clientIp($request)),
            Limit::perMinute(5)->by('account:'.hash('sha256', mb_strtolower(trim(is_string($request->input('correo')) ? $request->input('correo') : ''))).':'.$this->clientIp($request)),
        ]);
        RateLimiter::for('identity-public', fn (Request $request): Limit => Limit::perMinute(10)->by($this->clientIp($request)));
        RateLimiter::for('identity-onboarding', fn (Request $request): Limit => Limit::perMinute(5)->by($this->clientIp($request)));
        RateLimiter::for('identity-recovery', fn (Request $request): Limit => Limit::perMinute(5)->by($this->clientIp($request)));
        RateLimiter::for('identity-ruc', function (Request $request): array {
            $ruc = $request->input('ruc');
            $limits = [Limit::perMinute(15)->by('ip:'.$this->clientIp($request))];
            if (is_string($ruc) && preg_match('/^[0-9]{11}$/D', $ruc)) {
                $limits[] = Limit::perMinute(5)->by('ruc:'.hash_hmac('sha256', $ruc, (string) config('app.key')));
            }

            return $limits;
        });
        RateLimiter::for('identity-api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->getKey() ?? $this->clientIp($request)));
        RateLimiter::for('identity-internal', fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));
    }

    private function clientIp(Request $request): string
    {
        $fallback = $request->ip() ?? 'unknown';
        $key = (string) config('identity.gateway_client_ip_key');
        $header = $request->header('X-AgroCloud-Client-IP');
        $requestId = $request->attributes->get('requestId');
        if ($key === '' || ! is_string($header) || ! is_string($requestId)
            || ! preg_match('/^([0-9]{1,12});([^;]{1,45});([a-f0-9]{64})$/D', $header, $parts)) {
            return $fallback;
        }

        [, $timestamp, $ip, $signature] = $parts;
        if (abs(now()->timestamp - (int) $timestamp) > 60 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $fallback;
        }

        $expected = hash_hmac('sha256', implode("\n", [$requestId, $timestamp, $ip]), $key);

        return hash_equals($expected, $signature) ? $ip : $fallback;
    }
}
