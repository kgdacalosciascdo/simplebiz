<?php

namespace App\Providers;

use App\Support\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
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
        RateLimiter::for('auth', fn ($request) => Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('admin', fn ($request) => Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
