<?php

namespace App\Domain\Notification\Actions;

use App\Integrations\Sms\Exceptions\SmsProviderUnavailable;
use App\Integrations\Sms\Providers\GenNet\GenNetSmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use InvalidArgumentException;

/**
 * Asks a provider for its balance, for the administration screen (§30.2).
 *
 * A read, with nothing recorded. GenNet can say why it could not answer, and
 * that reason is passed on; a provider that does not report a balance returns
 * null, which the screen words as "this provider does not report a balance".
 */
class CheckSmsBalance
{
    public function __construct(
        protected SmsProviderManager $providers,
    ) {}

    /**
     * @throws InvalidArgumentException when the provider has no driver or is not configured
     * @throws SmsProviderUnavailable when the provider could not say
     */
    public function handle(string $provider): ?string
    {
        if (! in_array($provider, $this->providers->available(), true)) {
            throw new InvalidArgumentException(__('sms.test.unavailable'));
        }

        $driver = $this->providers->driver($provider);

        if (! $driver->isConfigured()) {
            throw new InvalidArgumentException(__('sms.test.not_configured'));
        }

        return $driver instanceof GenNetSmsProvider
            ? $driver->fetchBalance()
            : $driver->balance();
    }
}
