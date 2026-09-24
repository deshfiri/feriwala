<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Gateways\Eps\EpsGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * EPS (P2-21, §26).
 *
 * This file exists to hold a deliberate absence in place. EPS does not publish
 * its integration guide — it is issued to merchants on request — and the parts
 * that are public describe the shape of an integration without stating the
 * endpoints, the schemas, or how the HMAC's signed string is composed.
 *
 * So the driver refuses everything, and these tests make that refusal a
 * property somebody would have to deliberately remove rather than something
 * that could quietly drift into a half-built gateway taking real payments.
 */

beforeEach(function () {
    $this->gateway = app(EpsGateway::class);
});

it('declares no capability it cannot document', function () {
    expect($this->gateway->capabilities())->toBe([]);
});

it('refuses every operation rather than guessing at the protocol', function () {
    // Nothing reaches the network. A driver that sent a malformed request to a
    // payment provider would be worse than one that says it is not available.
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

it('fails closed on a notification claiming to be from EPS', function () {
    expect($this->gateway->verifyWebhookSignature(Request::create('/ipn', 'POST', ['status' => 'success'])))
        ->toBeFalse();
});

it('ships disabled and cannot be switched on', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $manager = testPlatformStaff(PlatformRole::PaymentManager);

    expect(app(PaymentGatewayManager::class)->isEnabled('eps'))->toBeFalse();

    // Even fully credentialled: a gateway that cannot confirm a payment with
    // its provider cannot safely take one.
    $settings = app(SettingsRepository::class);

    foreach (['merchant_id', 'store_id', 'username', 'password', 'hash_key'] as $key) {
        $settings->define(
            "payment.eps.sandbox.{$key}",
            'payment',
            SettingType::String,
            'filled-in',
            isEncrypted: true,
        );
    }

    expect(app(PaymentGatewayManager::class)->driver('eps')->isConfigured())->toBeTrue()
        ->and(fn () => app(ConfigureGateway::class)->setEnabled($manager, 'eps', true))
        ->toThrow(RuntimeException::class, 'cannot confirm a payment');
});

it('still holds the credentials EPS issues, encrypted', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $manager = testPlatformStaff(PlatformRole::PaymentManager);

    $this->actingAs($manager)
        ->put(route('admin.gateways.update'), [
            'gateway' => 'eps',
            'mode' => 'sandbox',
            'credentials' => ['hash_key' => 'a-real-hash-key'],
        ])
        ->assertRedirect();

    expect(Setting::query()->where('key', 'payment.eps.sandbox.hash_key')->value('value'))
        ->not->toBe('a-real-hash-key')
        ->and(app(SettingsRepository::class)->get('payment.eps.sandbox.hash_key'))
        ->toBe('a-real-hash-key');
});
