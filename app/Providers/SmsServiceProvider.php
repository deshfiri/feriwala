<?php

namespace App\Providers;

use App\Domain\Notification\Channels\SmsChannel;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
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

    public function boot(): void
    {
        /*
         * `sms` becomes a notification channel like `mail` and `database`
         * (§30). SMS is a way of delivering notifications the platform already
         * sends — a separate business-event system would mean two places
         * deciding what a customer is told, and two to forget to update.
         */
        Notification::resolved(function (ChannelManager $manager): void {
            $manager->extend('sms', fn (Application $app) => $app->make(SmsChannel::class));
        });
    }
}
