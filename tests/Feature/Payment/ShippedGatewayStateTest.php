<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * What this release ships with each gateway switched to (P2-38, D4, §26.4).
 *
 * One test that states the whole shipped position, so changing any part of it
 * is a deliberate act with a failing test attached rather than a config line
 * somebody edited while looking at something else.
 *
 * Stripe and PayPal are the headline: implemented against the driver contract,
 * shipped disabled, and unable to be switched on until merchant accounts exist
 * to credential them.
 */

it('ships every gateway but SSLCommerz switched off', function () {
    /*
     * SSLCommerz is the only provider with a live merchant account (D7), and
     * even it is offered only once somebody enters credentials — "enabled" and
     * "available" are different questions.
     *
     * Everything else arrives off. A provider nobody has decided about is off,
     * which is what the config default means.
     */
    $shipped = [];

    foreach (app(PaymentGatewayManager::class)->names() as $name) {
        $shipped[$name] = config("payment.gateways.{$name}.enabled") === true;
    }

    expect($shipped)->toBe([
        'sslcommerz' => true,
        'eps' => false,
        'surjopay' => false,
        'amarpay' => false,
        'bkash' => false,
        'nagad' => false,
        'stripe' => false,
        'paypal' => false,
    ]);
});

it('has a driver behind all eight', function () {
    // Every provider §26 names can at least hold its credentials. Two of them
    // can do nothing else, and say so.
    expect(app(PaymentGatewayManager::class)->implemented())->toHaveCount(8);
});

it('leaves EPS and Nagad able to do nothing at all', function () {
    /*
     * Their protocols could not be confirmed against official documentation —
     * both issue their integration guides to merchants rather than publishing
     * them — so they declare no capabilities and refuse every operation. That is
     * a decision, not an unfinished job.
     */
    $manager = app(PaymentGatewayManager::class);

    foreach (['eps', 'nagad'] as $name) {
        expect($manager->driver($name)->capabilities())->toBe([]);
    }
});

it('makes Stripe and PayPal fully capable and completely unavailable', function () {
    /*
     * The point of P2-38. The drivers are real — signatures, refunds, status
     * lookups, all built against published APIs — so the day a merchant account
     * is opened is a configuration day rather than a development one. Until
     * then there is nothing to credential them with.
     */
    $manager = app(PaymentGatewayManager::class);

    foreach (['stripe', 'paypal'] as $name) {
        $driver = $manager->driver($name);

        expect($driver->capabilities())->toContain(
            GatewayCapability::Initiate,
            GatewayCapability::Verify,
            GatewayCapability::WebhookSignature,
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
        )
            ->and($driver->isConfigured())->toBeFalse()
            ->and($manager->isEnabled($name))->toBeFalse()
            ->and($manager->isAvailable($name))->toBeFalse();
    }
});

it('will not let either be switched on without credentials', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $manager = testPlatformStaff(PlatformRole::PaymentManager);

    foreach (['stripe', 'paypal'] as $name) {
        expect(fn () => app(ConfigureGateway::class)->setEnabled($manager, $name, true))
            ->toThrow(RuntimeException::class);
    }
});

it('offers neither of them for a taka amount (D4)', function () {
    /*
     * Even fully credentialled they would not appear for a BDT charge: the base
     * ledger is in taka, version 1 performs no exchange-rate accounting, and
     * neither driver declares a currency it would have to convert.
     */
    $offered = app(PaymentGatewayManager::class)->availableFor(Currency::BDT);

    expect($offered)->not->toContain('stripe')
        ->and($offered)->not->toContain('paypal');
});

it('commits no credential for any of them', function () {
    /*
     * §26.4 and §36: credentials live encrypted in settings, never in committed
     * config. A config file holding one would put it in the repository, in every
     * clone of it, and in every backup of those.
     */
    $config = config('payment.gateways');

    foreach ($config as $name => $gateway) {
        // What a gateway may say about itself here: which driver serves it,
        // whether it is switched on, what it is called, and — for a developer
        // with no route to the sandbox — where a local stub stands in.
        expect(array_keys($gateway))->each->toBeIn(['driver', 'enabled', 'label', 'sandbox_host']);

        foreach ($gateway as $key => $value) {
            expect($key)->not->toMatch('/secret|password|token|credential|store_id|api_key/i', $name.'.'.$key.' looks like a credential.')
                ->and(is_string($value) && $value !== '' ? $value : 'x')
                ->not->toMatch('/^(sk_|pk_|live_)/i', $name.'.'.$key.' holds what looks like a key.');
        }
    }
});
