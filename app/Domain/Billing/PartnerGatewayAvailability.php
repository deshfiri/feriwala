<?php

namespace App\Domain\Billing;

use App\Domain\Settings\SettingsRepository;

/**
 * Whether Admin lets Partners use their own payment gateway, and which providers (D36).
 *
 * Two settings, both Admin's to decide. The global switch is off until somebody
 * turns it on: nobody has approved a Partner collecting a customer's payment
 * into their own account, so the answer to a Storefront that asks is no. The
 * allow-list is empty until Admin names providers, so switching the feature on
 * does not offer every provider at once.
 *
 * This decides only whether a Partner may **configure** a gateway or **start a
 * new payment** with one. A payment already made is never affected by turning
 * the feature off: it keeps the version it was started with.
 */
class PartnerGatewayAvailability
{
    public const ENABLED = 'payment.partner_gateways.enabled';

    public const PROVIDERS = 'payment.partner_gateways.providers';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * The global switch.
     */
    public function isEnabled(): bool
    {
        return $this->settings->get(self::ENABLED) === true;
    }

    /**
     * Providers Admin has allowed Partners to configure.
     *
     * @return list<string>
     */
    public function allowedProviders(): array
    {
        $providers = $this->settings->get(self::PROVIDERS, []);

        return is_array($providers)
            ? array_values(array_filter($providers, fn (mixed $provider) => is_string($provider) && $provider !== ''))
            : [];
    }

    /**
     * Whether a Partner may configure, and start a payment with, this provider.
     */
    public function allows(string $provider): bool
    {
        return $this->isEnabled() && in_array($provider, $this->allowedProviders(), true);
    }
}
