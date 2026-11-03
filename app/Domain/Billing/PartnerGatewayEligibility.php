<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Data\PartnerGatewayCandidate;
use App\Domain\Billing\Enums\PartnerGatewayFallbackReason;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Throwable;

/**
 * Whether a Storefront's own gateway may take a **new** payment (D36).
 *
 * The gateway-side half of the Model B decision; the order-side half (account
 * type, stock source, defined amounts, the receivable snapshot) is made where the
 * order is placed. Asked before any payment exists, and it never contacts a
 * provider: the driver is built only to read what it is and what it needs.
 *
 * Every refusal is a named reason, never a message: nothing a provider said and
 * nothing from the credentials can reach a log or a reply through here.
 *
 * Only ever about a **new** attempt. A payment already made resolves its own
 * frozen version and never asks this.
 */
class PartnerGatewayEligibility
{
    public function __construct(
        protected PartnerGatewayAvailability $availability,
        protected PartnerGatewayResolver $resolver,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function candidate(Website $website, Currency $currency): PartnerGatewayCandidate
    {
        $configuration = PartnerGatewayConfiguration::query()
            ->where('website_id', $website->id)
            ->where('business_account_id', $website->business_account_id)
            ->first();

        if ($configuration === null || ! $configuration->isActive()) {
            return PartnerGatewayCandidate::none();
        }

        $provider = $configuration->provider;

        if (! $this->availability->isEnabled()) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::FeatureDisabled, $provider);
        }

        if (! $this->availability->allows($provider)) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::ProviderNotAllowed, $provider);
        }

        $version = $this->resolver->activeVersionFor($website);

        if ($version === null) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::ConfigurationUnavailable, $provider);
        }

        if ($version->mode !== 'live' && app()->isProduction()) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::SandboxNotAllowedInProduction, $provider);
        }

        if (! $this->gateways->isImplemented($version->provider)) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::ProviderUnsupported, $provider);
        }

        try {
            $driver = $this->gateways->scopedDriver($version->provider, $version->mode, $version->credentials);
        } catch (Throwable) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::ProviderUnsupported, $provider);
        }

        if (! $driver->supports(GatewayCapability::Verify)) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::ProviderUnsupported, $provider);
        }

        if (! $driver->isConfigured()) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::CredentialsIncomplete, $provider);
        }

        // Directly, never through an exchange rate: what the Partner is paid is
        // what the customer was charged, and a converted figure is not.
        if (! in_array($currency, $driver->supportedCurrencies(), true)) {
            return PartnerGatewayCandidate::unusable(PartnerGatewayFallbackReason::CurrencyUnsupported, $provider);
        }

        return PartnerGatewayCandidate::usable($version);
    }
}
