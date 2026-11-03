<?php

use App\Domain\Billing\Enums\PartnerGatewayProviderSupport;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\PaymentGatewayManager;

/*
 * What a provider is allowed to be called for a Partner-owned payment (D36). Only
 * SSLCommerz has contract tests today, so only it is production-ready; the rest are
 * implemented but unverified, and Nagad cannot verify a payment on the server at
 * all. The screens read this one answer rather than guessing from the driver.
 */

it('calls only the provider with contract tests production-ready', function () {
    expect(PartnerGatewayProviderSupport::for('sslcommerz'))->toBe(PartnerGatewayProviderSupport::SupportedAndTested)
        ->and(PartnerGatewayProviderSupport::for('sslcommerz')->isProductionReady())->toBeTrue();

    foreach (['surjopay', 'amarpay', 'bkash', 'stripe', 'paypal', 'eps'] as $provider) {
        expect(PartnerGatewayProviderSupport::for($provider))->toBe(PartnerGatewayProviderSupport::ImplementedUnverified)
            ->and(PartnerGatewayProviderSupport::for($provider)->isProductionReady())->toBeFalse()
            ->and(PartnerGatewayProviderSupport::for($provider)->canCarryPartnerPayments())->toBeTrue();
    }
});

it('does not support Nagad, or any provider nobody has classified', function () {
    foreach (['nagad', 'something-new', ''] as $provider) {
        expect(PartnerGatewayProviderSupport::for($provider))->toBe(PartnerGatewayProviderSupport::Unsupported)
            ->and(PartnerGatewayProviderSupport::for($provider)->isProductionReady())->toBeFalse()
            ->and(PartnerGatewayProviderSupport::for($provider)->canCarryPartnerPayments())->toBeFalse();
    }
});

it('classifies every provider Feriwala has a driver for, in agreement with what its driver can do', function () {
    $gateways = app(PaymentGatewayManager::class);

    foreach ($gateways->names() as $name) {
        $support = PartnerGatewayProviderSupport::for($name);
        $verifies = $gateways->scopedDriver($name, 'sandbox', [])->supports(GatewayCapability::Verify);

        // A provider is never called usable for a Partner when its driver cannot verify a payment.
        expect($support->canCarryPartnerPayments() && ! $verifies)->toBeFalse("{$name} is classed usable but cannot verify");
    }
});

it('gives every status a label in both languages', function () {
    foreach (PartnerGatewayProviderSupport::cases() as $support) {
        foreach (['en', 'bn'] as $locale) {
            app()->setLocale($locale);

            expect($support->label())->not->toBe('')
                ->and(__('status.partner_gateway_support.'.$support->value))->not->toBe('status.partner_gateway_support.'.$support->value);
        }
    }
});
