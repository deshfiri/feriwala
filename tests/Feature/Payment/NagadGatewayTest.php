<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Gateways\Nagad\NagadGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * Nagad (P2-26, §26).
 *
 * Like EPS, this file holds a deliberate absence in place. Nagad's Merchant API
 * integration guide is issued to registered merchants, and its RSA signing and
 * encryption scheme has too many details that all have to be right — which
 * fields, in what order, with what padding and digest — for any of it to be
 * inferred from the outside.
 */

beforeEach(function () {
    $this->gateway = app(NagadGateway::class);
});

it('declares no capability it cannot document', function () {
    expect($this->gateway->capabilities())->toBe([]);
});

it('refuses every operation rather than guessing at an RSA scheme', function () {
    Http::fake();

    $intent = new PaymentIntent(
        reference: 'PAY-1',
        amount: Money::fromDecimal('1000.00'),
        customerName: 'Test',
        customerEmail: 'test@example.test',
        customerMobile: null,
        successUrl: 'https://erp.test/success',
        failUrl: 'https://erp.test/fail',
        cancelUrl: 'https://erp.test/cancel',
        ipnUrl: 'https://erp.test/ipn',
        description: 'Test',
    );

    expect(fn () => $this->gateway->initiate($intent))->toThrow(GatewayCapabilityMissing::class)
        ->and(fn () => $this->gateway->verify('anything'))->toThrow(GatewayCapabilityMissing::class)
        ->and(fn () => $this->gateway->status('PAY-1'))->toThrow(GatewayCapabilityMissing::class)
        ->and(fn () => $this->gateway->handleCallback(Request::create('/return')))
        ->toThrow(GatewayCapabilityMissing::class);

    Http::assertNothingSent();
});

it('fails closed on a notification claiming to be from Nagad', function () {
    expect($this->gateway->verifyWebhookSignature(Request::create('/ipn', 'POST', ['status' => 'Success'])))
        ->toBeFalse();
});

it('ships disabled and cannot be switched on', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $manager = testPlatformStaff(PlatformRole::PaymentManager);

    $settings = app(SettingsRepository::class);

    foreach (['merchant_id', 'merchant_number', 'merchant_private_key', 'nagad_public_key'] as $key) {
        $settings->define(
            "payment.nagad.sandbox.{$key}",
            'payment',
            SettingType::String,
            'filled-in',
            isEncrypted: true,
        );
    }

    expect(app(PaymentGatewayManager::class)->isEnabled('nagad'))->toBeFalse()
        ->and(fn () => app(ConfigureGateway::class)->setEnabled($manager, 'nagad', true))
        ->toThrow(RuntimeException::class, 'cannot confirm a payment');
});

it('holds the merchant private key encrypted and never gives it back', function () {
    /*
     * The most dangerous value in the settings table: a merchant private key is
     * what proves a request came from Feriwala. It goes in under the same
     * write-only rules as a password and never reaches a screen (§42).
     */
    $this->seed(RolesAndPermissionsSeeder::class);
    $manager = testPlatformStaff(PlatformRole::PaymentManager);

    $this->actingAs($manager)
        ->put(route('admin.gateways.update'), [
            'gateway' => 'nagad',
            'mode' => 'sandbox',
            'credentials' => ['merchant_private_key' => 'MIIEvQIBADANBgkqhki-not-a-real-key'],
        ])
        ->assertRedirect();

    expect(Setting::query()->where('key', 'payment.nagad.sandbox.merchant_private_key')->value('value'))
        ->not->toContain('MIIEvQIBADANBgkqhki-not-a-real-key');

    $this->actingAs($manager)
        ->get(route('admin.gateways.index'))
        ->assertOk()
        ->assertDontSee('MIIEvQIBADANBgkqhki-not-a-real-key', escape: false);
});
