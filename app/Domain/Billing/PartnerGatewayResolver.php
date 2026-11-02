<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Billing\Models\Payment;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use InvalidArgumentException;

/**
 * Finds the Partner gateway a payment must be talked to through (D36).
 *
 * Two different questions, kept apart on purpose:
 *
 *   - **A new payment attempt** asks {@see activeVersionFor()}: what is the
 *     Storefront's gateway *now*, and may it be used at all?
 *   - **Everything after the attempt exists** (verifying, settling, reconciling,
 *     refund status) asks {@see driverForPayment()}, which resolves the exact
 *     version frozen on the payment. It never reads the Storefront's current
 *     configuration, so a changed credential, a changed mode or a switch back to
 *     the Platform gateway cannot reach an attempt that was already made.
 *
 * No decrypted credential is kept anywhere: the version is read, the driver is
 * built on it, and both go out of scope with the caller.
 */
class PartnerGatewayResolver
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PartnerGatewayAvailability $availability,
    ) {}

    /**
     * The version a new payment attempt would start with, or null when the
     * Storefront should use the Platform gateway: nothing is configured, it is
     * switched off, or Admin no longer allows the feature or the provider.
     */
    public function activeVersionFor(Website $website): ?PartnerGatewayConfigurationVersion
    {
        $configuration = PartnerGatewayConfiguration::query()
            ->where('website_id', $website->id)
            ->where('business_account_id', $website->business_account_id)
            ->first();

        if ($configuration === null || ! $configuration->isActive() || ! $this->availability->allows($configuration->provider)) {
            return null;
        }

        $version = $configuration->latestVersion;

        return $version !== null
            && $version->business_account_id === $website->business_account_id
            && $version->website_id === $website->id
            ? $version
            : null;
    }

    /**
     * The driver for a Partner-owned payment, bound to the credentials it was started with.
     *
     * @throws InvalidArgumentException when the payment is not Partner-owned
     * @throws GatewayUnavailable when its frozen version is missing or no longer fits the payment
     */
    public function driverForPayment(Payment $payment): PaymentGateway
    {
        if ($payment->gateway_ownership !== GatewayOwnership::Partner) {
            throw new InvalidArgumentException('Only a Partner-owned payment has a Partner gateway.');
        }

        $version = $payment->partnerGatewayVersion;

        if ($version === null
            || $version->business_account_id !== $payment->business_account_id
            || $version->website_id !== $payment->website_id
            || $version->provider !== $payment->gateway) {
            throw GatewayUnavailable::forGateway((string) $payment->gateway, 'the configuration this payment was made with is not available');
        }

        return $this->gateways->scopedDriver($version->provider, $version->mode, $version->credentials);
    }
}
