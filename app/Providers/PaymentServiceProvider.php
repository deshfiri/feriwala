<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Payment-wide wiring (§26.4).
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * A ceiling on the notification endpoint.
     *
     * That endpoint is unauthenticated by necessity. Signed providers are
     * protected by their signature, but three of the eight do not sign at all —
     * documented behaviour for two of them — so without this, anybody could
     * drive outbound verification calls by posting references at us.
     *
     * Counted per gateway **and** per address, so one provider's retry storm
     * cannot spend another's budget, and a misbehaving sender cannot starve a
     * genuine one. The ceiling is deliberately generous: a provider working
     * through a real backlog must never be turned away, and the limit exists for
     * a sender that is not a provider at all.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('payment-webhooks', function (Request $request) {
            $gateway = $request->route('gateway');

            return Limit::perMinute((int) config('payment.webhook_rate_limit', 120))
                ->by((is_string($gateway) ? $gateway : 'unknown').'|'.$request->ip());
        });
    }
}
