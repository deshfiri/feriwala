<?php

namespace App\Integrations\Payment\Gateways\SslCommerz;

use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;

/**
 * SSLCommerz credentials, read from encrypted settings (§26.4, §36, D7).
 *
 * Never from committed config and never from code. Settings hold them encrypted
 * at rest, and separate sandbox and live entries mean testing cannot
 * accidentally take real money — or worse, put test transactions through a live
 * merchant account.
 */
class SslCommerzCredentials
{
    public const SANDBOX_MODE = 'sandbox';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function isSandbox(): bool
    {
        return $this->settings->get('payment.sslcommerz.mode', self::SANDBOX_MODE) === self::SANDBOX_MODE;
    }

    public function storeId(): string
    {
        return $this->require('store_id');
    }

    public function storePassword(): string
    {
        return $this->require('store_password');
    }

    /**
     * Whether the gateway is configured enough to be offered to a user.
     *
     * Checked before showing SSLCommerz as a payment option, so a partially
     * configured gateway fails at the settings screen rather than at checkout.
     */
    public function areConfigured(): bool
    {
        return $this->value('store_id') !== null
            && $this->value('store_password') !== null;
    }

    protected function require(string $key): string
    {
        $value = $this->value($key);

        if ($value === null || $value === '') {
            throw GatewayUnavailable::missingCredentials('sslcommerz');
        }

        return $value;
    }

    /**
     * Sandbox and live credentials live under separate keys so switching mode
     * cannot pick up the wrong pair.
     */
    protected function value(string $key): ?string
    {
        $mode = $this->isSandbox() ? 'sandbox' : 'live';

        $value = $this->settings->get("payment.sslcommerz.{$mode}.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
