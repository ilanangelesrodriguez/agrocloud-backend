<?php

namespace App\Providers;

use App\Shared\Tenancy\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CompanyContext::class, fn () => new CompanyContext);
    }

    public function boot(): void
    {
        Auth::viaRequest('identity', fn (Request $request) => $request->attributes->get('agricultural_actor'));
        RateLimiter::for('agricultural-public', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('agricultural-api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id_usuario ?? $request->ip()));
    }
}
