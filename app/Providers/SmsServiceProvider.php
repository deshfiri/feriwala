<?php

namespace App\Providers;

use App\Integrations\Sms\Contracts\SmsProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Resolves the configured SMS provider (§30.1).
 *
 * Adding a provider means writing one class and naming it in config/sms.php —
 * no change here and none in the code that sends messages.
 */
class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, function (Application $app) {
            $name = (string) config('sms.default');
            $driver = config("sms.providers.{$name}.driver");

            if ($driver === null) {
                throw new RuntimeException(
                    "SMS provider [{$name}] has no driver configured. "
                    .'Set SMS_PROVIDER to a provider that is implemented.'
                );
            }

            return $app->make($driver);
        });
    }
}
