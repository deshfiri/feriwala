<?php

namespace App\Providers;

use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Resolves the configured SMS provider (§30.1).
 *
 * Adding a provider means writing one class and naming it in config/sms.php —
 * no change here and none in the code that sends messages.
 *
 * The binding is **not** a singleton any more. An administrator can switch
 * provider from the settings screen, and a singleton resolved at the first send
 * of the day would keep using the old one until the workers restarted.
 */
class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProviderManager::class);

        $this->app->bind(
            SmsProvider::class,
            fn (Application $app) => $app->make(SmsProviderManager::class)->driver(),
        );
    }
}
