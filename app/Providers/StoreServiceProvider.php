<?php

namespace App\Providers;

use App\Integrations\Store\Contracts\HostResolver;
use App\Integrations\Store\Support\DnsHostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * External store connections (D29).
 */
class StoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        /*
         * Store notifications. Keyed by the store the URL names and the
         * sender's address: a store working through a backlog is let through,
         * a sender hammering one store's endpoint is not.
         */
        RateLimiter::for('store-webhooks', function (Request $request) {
            $store = $request->route('store');

            return Limit::perMinute((int) config('stores.webhook_rate_limit', 240))
                ->by((is_string($store) ? $store : 'unknown').'|'.$request->ip());
        });

        // "Sync now" and "Test connection", per person: each one calls the
        // partner's store, and a store is not ours to flood.
        RateLimiter::for('store-actions', function (Request $request) {
            return Limit::perMinute(6)->by('store-actions|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
