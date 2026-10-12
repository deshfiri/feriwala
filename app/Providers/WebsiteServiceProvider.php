<?php

namespace App\Providers;

use App\Integrations\Dns\Contracts\TxtRecordResolver;
use App\Integrations\Dns\SystemTxtRecordResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Partner websites on the shared storefront (D30).
 */
class WebsiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TxtRecordResolver::class, SystemTxtRecordResolver::class);
    }

    public function boot(): void
    {
        /*
         * The certificate check Caddy makes before issuing (D30 §4). Caddy asks
         * once per new name, so a flood of asks is somebody probing names, not
         * the server doing its job.
         */
        RateLimiter::for('tls-ask', function (Request $request) {
            return Limit::perMinute((int) config('website.tls.ask_rate_limit', 120))
                ->by('tls-ask|'.$request->ip());
        });
    }
}
