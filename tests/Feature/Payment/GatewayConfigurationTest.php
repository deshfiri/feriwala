<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\PaymentGatewayManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Gateway configuration (P1-51, §26.4, D7).
 *
 * Two rules carry this file. A gateway is offered only when it is switched on
 * **and** credentialled — anything less sends somebody through the whole fee
 * breakdown to fail on the last click. And a stored secret never comes back out:
 * not to a prop, not to a form, not to an audit entry.
 */

function gatewayTestCredentials(string $mode = 'sandbox'): void
{
    $settings = app(SettingsRepository::class);

    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, $mode);
    $settings->define("payment.sslcommerz.{$mode}.store_id", 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define("payment.sslcommerz.{$mode}.store_password", 'payment', SettingType::String, 'pass', isEncrypted: true);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
});

describe('when a gateway is offered', function () {
    it('is not offered until it has credentials', function () {
        // Enabled in config, but nobody has entered a store id. Offering it
        // would take an applicant to a checkout that cannot start a session.
        expect(app(PaymentGatewayManager::class)->available())->toBe([]);
    });

    it('is offered once it is switched on and credentialled', function () {
        gatewayTestCredentials();

        expect(app(PaymentGatewayManager::class)->available())->toBe(['sslcommerz']);
    });

    it('is not offered when the credentials are for the other mode', function () {
        /*
         * Sandbox and live are stored separately on purpose. A live switch with
         * only sandbox credentials must read as unconfigured rather than
         * silently sending real customers at a test merchant account.
         */
        gatewayTestCredentials('sandbox');
        app(SettingsRepository::class)->set('payment.sslcommerz.mode', 'live');

        expect(app(PaymentGatewayManager::class)->isAvailable('sslcommerz'))->toBeFalse();
    });

    it('reports every gateway §26 names, not only the working one', function () {
        // "Why can nobody pay by bKash" is answered by seeing it listed as not
        // built yet. An empty list answers nothing.
        $catalogue = app(PaymentGatewayManager::class)->catalogue();

        expect($catalogue)->toHaveCount(8);

        $bkash = collect($catalogue)->firstWhere('name', 'bkash');

        expect($bkash['is_implemented'])->toBeFalse()
            ->and($bkash['is_available'])->toBeFalse();
    });
});

describe('the checkout', function () {
    it('offers nothing to pay with while no gateway is credentialled', function () {
        $settings = app(SettingsRepository::class);
        $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
        $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');

        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);
        $package = Package::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'fee_minor' => 500000,
            'validity_days' => 365,
        ]);

        $this->actingAs($account->owner)->post(route('packages.select', $package));

        $this->actingAs($account->owner)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('gateways', 0));

        // And the server refuses a gateway the screen never offered.
        $this->actingAs($account->owner)
            ->post(route('checkout.pay'), ['gateway' => 'sslcommerz'])
            ->assertSessionHasErrors('gateway');
    });
});

describe('the settings screen', function () {
    it('lists every gateway and what each still needs', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.gateways.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/gateways')
                ->has('gateways', 8)
                ->where('gateways.0.name', 'sslcommerz')
                ->where('gateways.0.is_configured', false)
                ->where('can.manage', true),
            );
    });

    it('never sends a credential to the browser', function () {
        /*
         * The whole reason this screen reports presence rather than value. A
         * secret in an Inertia prop is a secret in the page source, in the
         * browser's history, and in every error report that captures it (§42).
         */
        gatewayTestCredentials();
        app(SettingsRepository::class)->set('payment.sslcommerz.sandbox.store_password', 'super-secret-value');

        $response = $this->actingAs($this->manager)->get(route('admin.gateways.index'));

        $response->assertOk()
            ->assertDontSee('super-secret-value', escape: false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('gateways.0.is_configured', true)
                ->missing('gateways.0.store_id')
                ->missing('gateways.0.store_password'),
            );
    });

    it('saves credentials encrypted at rest', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'sandbox',
                'store_id' => 'feriwala-store',
                'store_password' => 'a-real-password',
            ])
            ->assertRedirect();

        $stored = Setting::query()
            ->where('key', 'payment.sslcommerz.sandbox.store_password')
            ->value('value');

        expect($stored)->not->toBe('a-real-password')
            ->and(app(SettingsRepository::class)->get('payment.sslcommerz.sandbox.store_password'))
            ->toBe('a-real-password')
            ->and(app(PaymentGatewayManager::class)->isAvailable('sslcommerz'))->toBeTrue();
    });

    it('keeps what is stored when a field is left blank', function () {
        // The form cannot show what is already there, so it cannot ask to keep
        // it either — blank has to mean "leave it", not "wipe it".
        gatewayTestCredentials();

        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'sandbox',
                'store_id' => '',
                'store_password' => '',
            ]);

        expect(app(SettingsRepository::class)->get('payment.sslcommerz.sandbox.store_id'))
            ->toBe('store');
    });

    it('records what changed without recording the secret', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'sandbox',
                'store_id' => 'feriwala-store',
                'store_password' => 'a-real-password',
            ]);

        $entry = AuditLog::query()->where('action', 'payment.gateway_configured')->firstOrFail();

        expect(json_encode($entry->after))->not->toContain('a-real-password')
            ->and($entry->after['credentials_set'])->toBe(['store_id', 'store_password']);
    });

    it('is closed to somebody who may price fees but not hold the keys', function () {
        /*
         * Setting a fee changes an invoice. Holding a merchant account's
         * credentials means holding the keys to where the money lands — a
         * narrower permission on purpose.
         */
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $this->actingAs($viewer)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'sandbox',
                'store_id' => 'nope',
            ])
            ->assertForbidden();

        expect(Setting::query()->where('key', 'payment.sslcommerz.sandbox.store_id')->exists())
            ->toBeFalse();
    });

    it('refuses to configure a gateway with no driver', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'bkash',
                'mode' => 'sandbox',
                'store_id' => 'nope',
            ])
            ->assertSessionHasErrors('gateway');
    });

    it('refuses a mode that is neither sandbox nor live', function () {
        expect(fn () => app(ConfigureGateway::class)->sslCommerz($this->manager, 'production', []))
            ->toThrow(InvalidArgumentException::class);
    });
});
