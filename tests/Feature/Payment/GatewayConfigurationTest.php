<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
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
        // "Why can nobody pay by bKash" is answered by seeing it listed with
        // what it still needs. An empty list answers nothing.
        $catalogue = app(PaymentGatewayManager::class)->catalogue();

        expect($catalogue)->toHaveCount(8);

        $bkash = collect($catalogue)->firstWhere('name', 'bkash');

        expect($bkash['is_implemented'])->toBeTrue()
            ->and($bkash['is_configured'])->toBeFalse()
            ->and($bkash['is_available'])->toBeFalse()
            ->and($bkash['missing_configuration'])->toContain('app_key');
    });

    it('is never offered by a driver that cannot confirm a payment', function () {
        /*
         * Nagad rests here: a class is wired up and its credentials have
         * somewhere to live, but its protocol could not be confirmed against
         * official documentation, so it declares nothing.
         *
         * Checked at the catalogue as well as when enabling, because this is
         * what the checkout reads. "Switched on" is a decision somebody made
         * once; this is whether the gateway can work at all.
         */
        $catalogue = collect(app(PaymentGatewayManager::class)->catalogue());

        $nagad = $catalogue->firstWhere('name', 'nagad');

        expect($nagad['is_implemented'])->toBeTrue()
            ->and($nagad['is_operational'])->toBeFalse()
            ->and($nagad['capabilities'])->toBe([])
            ->and($nagad['is_available'])->toBeFalse();

        expect($catalogue->firstWhere('name', 'sslcommerz')['is_operational'])->toBeTrue();
    });

    it('lets EPS confirm a payment but still keeps it unavailable with no credentials configured', function () {
        /*
         * Initiate/Verify/StatusQuery are real (EpsGatewayTest), so EPS is
         * "operational" here unlike Nagad -- what still keeps it off the
         * checkout is that nobody has entered a real merchant account's
         * credentials, exactly the same gap Stripe and PayPal ship with.
         */
        $eps = collect(app(PaymentGatewayManager::class)->catalogue())->firstWhere('name', 'eps');

        expect($eps['is_implemented'])->toBeTrue()
            ->and($eps['is_operational'])->toBeTrue()
            ->and($eps['capabilities'])->toContain('verify')
            ->and($eps['is_configured'])->toBeFalse()
            ->and($eps['is_available'])->toBeFalse();
    });
});

describe('the checkout', function () {
    it('offers nothing to pay with while no gateway is credentialled', function () {
        $settings = app(SettingsRepository::class);
        $settings->define('billing.registration_fee', 'billing', SettingType::Money, '1000.00');
        $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');

        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);
        $package = Package::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'fee' => Money::fromDecimal('5000.00', Currency::BDT),
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
                'credentials' => [
                    'store_id' => 'feriwala-store',
                    'store_password' => 'a-real-password',
                ],
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
                'credentials' => ['store_id' => '', 'store_password' => ''],
            ]);

        expect(app(SettingsRepository::class)->get('payment.sslcommerz.sandbox.store_id'))
            ->toBe('store');
    });

    it('records what changed without recording the secret', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'sandbox',
                'credentials' => [
                    'store_id' => 'feriwala-store',
                    'store_password' => 'a-real-password',
                ],
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
                'credentials' => ['store_id' => 'nope'],
            ])
            ->assertForbidden();

        expect(Setting::query()->where('key', 'payment.sslcommerz.sandbox.store_id')->exists())
            ->toBeFalse();
    });

    it('refuses to hold credentials for a gateway that does not exist', function () {
        // A settings table is not somewhere to park secrets for a provider
        // nobody has written a driver for.
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'some-other-provider',
                'mode' => 'sandbox',
                'credentials' => ['store_id' => 'nope'],
            ])
            ->assertSessionHasErrors('gateway');

        expect(Setting::query()->where('key', 'like', 'payment.some-other-provider.%')->exists())
            ->toBeFalse();
    });

    it('refuses a mode that is neither sandbox nor live', function () {
        expect(fn () => app(ConfigureGateway::class)->handle($this->manager, 'sslcommerz', 'production', []))
            ->toThrow(InvalidArgumentException::class);
    });

    it('stores nothing for a key the provider never asked for', function () {
        /*
         * The form is generated from what the driver declares it needs. A
         * settings table is not a place to accept arbitrary named secrets from
         * whatever somebody chose to post.
         */
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'sandbox',
                'credentials' => ['secret_api_key' => 'not-a-sslcommerz-field'],
            ]);

        expect(Setting::query()->where('key', 'payment.sslcommerz.sandbox.secret_api_key')->exists())
            ->toBeFalse();
    });
});

describe('the payments switch list', function () {
    it('lists every gateway with its switch state and no credential', function () {
        gatewayTestCredentials();
        app(SettingsRepository::class)->set('payment.sslcommerz.sandbox.store_password', 'super-secret-value');

        $response = $this->actingAs($this->manager)->get(route('admin.gateways.switches'));

        $response->assertOk()
            ->assertDontSee('super-secret-value', escape: false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/payment-switches')
                ->has('gateways', 8)
                ->where('gateways.0.name', 'sslcommerz')
                ->where('gateways.0.is_enabled', true)
                ->where('gateways.0.is_configured', true)
                ->where('can.manage', true),
            );
    });

    it('is where the Payments card in the settings hub leads', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sections.0.items.2.key', 'payments')
                ->where('sections.0.items.2.href', route('admin.gateways.switches')),
            );
    });

    it('switches a gateway off and back on from the list, and says so on the next visit', function () {
        gatewayTestCredentials();

        $this->actingAs($this->manager)
            ->from(route('admin.gateways.switches'))
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => false])
            ->assertRedirect(route('admin.gateways.switches'));

        $this->actingAs($this->manager)->get(route('admin.gateways.switches'))
            ->assertInertia(fn (Assert $page) => $page->where('gateways.0.is_enabled', false));

        $this->actingAs($this->manager)
            ->from(route('admin.gateways.switches'))
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => true])
            ->assertRedirect(route('admin.gateways.switches'));

        $this->actingAs($this->manager)->get(route('admin.gateways.switches'))
            ->assertInertia(fn (Assert $page) => $page->where('gateways.0.is_enabled', true));
    });

    it('shows a viewer the list read-only and refuses their switch', function () {
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $this->actingAs($viewer)->get(route('admin.gateways.switches'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        $this->actingAs($viewer)
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => false])
            ->assertForbidden();
    });

    it('is closed to people who may not see payment settings', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->get(route('admin.gateways.switches'))
            ->assertForbidden();
    });
});

describe('switching a gateway on', function () {
    it('refuses while a required credential is missing', function () {
        /*
         * "Enabled" is the promise that somebody can actually pay with it.
         * Making that promise with half the credentials sends an applicant
         * through the whole fee breakdown to fail on the last click (§26.4).
         */
        $this->actingAs($this->manager)
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => true])
            ->assertSessionHasErrors('enabled');

        // Refused means nothing was decided: no switch was written, and the
        // gateway is still not something a payer can be sent to.
        expect(Setting::query()->where('key', 'payment.sslcommerz.enabled')->exists())->toBeFalse()
            ->and(app(PaymentGatewayManager::class)->isAvailable('sslcommerz'))->toBeFalse();
    });

    it('names what is still missing rather than just refusing', function () {
        // Half-configured: "this gateway is not ready" leaves somebody guessing
        // which of the fields they did not fill in.
        app(SettingsRepository::class)->define(
            'payment.sslcommerz.sandbox.store_id',
            'payment',
            SettingType::String,
            'store',
            isEncrypted: true,
        );

        expect(fn () => app(ConfigureGateway::class)->setEnabled($this->manager, 'sslcommerz', true))
            ->toThrow(RuntimeException::class, 'store password');
    });

    it('switches on once every credential is present', function () {
        gatewayTestCredentials();

        $this->actingAs($this->manager)
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => true])
            ->assertRedirect();

        expect(app(PaymentGatewayManager::class)->isEnabled('sslcommerz'))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'payment.gateway_enabled')->exists())->toBeTrue();
    });

    it('switches back off without touching the stored credentials', function () {
        gatewayTestCredentials();

        $this->actingAs($this->manager)
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => false]);

        expect(app(PaymentGatewayManager::class)->isEnabled('sslcommerz'))->toBeFalse()
            ->and(app(SettingsRepository::class)->get('payment.sslcommerz.sandbox.store_id'))->toBe('store');
    });

    it('goes off again when a mode change leaves it uncredentialled', function () {
        /*
         * The dangerous shape: enabled, credentialled for sandbox, switched to
         * live. Left alone it would be an enabled gateway with no live
         * credentials — and §26.4 is explicit that sandbox credentials must not
         * end up operating against live endpoints.
         */
        gatewayTestCredentials();

        $this->actingAs($this->manager)
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => true]);

        $this->actingAs($this->manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'sslcommerz',
                'mode' => 'live',
                'credentials' => [],
            ]);

        expect(app(PaymentGatewayManager::class)->isEnabled('sslcommerz'))->toBeFalse()
            ->and(app(PaymentGatewayManager::class)->isAvailable('sslcommerz'))->toBeFalse();
    });

    it('is closed to somebody who may not hold the keys', function () {
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $this->actingAs($viewer)
            ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => true])
            ->assertForbidden();
    });
});

describe('what a gateway says it can do', function () {
    it('reports its capabilities and currencies rather than assuming them', function () {
        $sslcommerz = collect(app(PaymentGatewayManager::class)->catalogue())
            ->firstWhere('name', 'sslcommerz');

        expect($sslcommerz['capabilities'])->toContain(GatewayCapability::Verify->value)
            ->and($sslcommerz['currencies'])->toContain(Currency::BDT->value);
    });

    it('refuses an operation the provider has no endpoint for', function () {
        /*
         * The guarantee every driver in this batch rests on, tested against a
         * gateway that declares nothing — the resting state of a provider whose
         * protocol could not be confirmed against its own documentation.
         *
         * The alternative to refusing is guessing at somebody else's protocol,
         * and a guess about a refund is a guess about real money.
         */
        $driver = new class extends Gateway
        {
            public function capabilities(): array
            {
                return [];
            }

            public function initiate(PaymentIntent $intent): GatewayRedirect
            {
                throw new RuntimeException('Not reached.');
            }

            public function handleCallback(Request $request): GatewayResult
            {
                throw new RuntimeException('Not reached.');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                return false;
            }

            public function verify(string $gatewayReference): GatewayResult
            {
                throw new RuntimeException('Not reached.');
            }

            protected function credentials(): GatewayCredentials
            {
                return new class(app(SettingsRepository::class)) extends GatewayCredentials
                {
                    public function gateway(): string
                    {
                        return 'unconfirmed';
                    }

                    public function requiredKeys(): array
                    {
                        return [];
                    }
                };
            }
        };

        expect($driver->supports(GatewayCapability::RefundFull))->toBeFalse()
            ->and(fn () => $driver->refund(new RefundIntent(
                reference: 'PAY-1',
                gatewayReference: 'BANK-1',
                amount: Money::fromDecimal('1.00'),
                originalAmount: Money::fromDecimal('1.00'),
                reason: 'Test',
                idempotencyKey: 'refund:PAY-1:1',
            )))->toThrow(GatewayCapabilityMissing::class)
            ->and(fn () => $driver->refundStatus('anything'))->toThrow(GatewayCapabilityMissing::class)
            ->and(fn () => $driver->status('PAY-1'))->toThrow(GatewayCapabilityMissing::class);
    });

    it('names the gateway and the operation when it refuses', function () {
        // Reaching this exception means a screen offered a button it should not
        // have, so it says which one and for whom.
        $driver = app(PaymentGatewayManager::class)->driver('sslcommerz');

        expect(GatewayCapabilityMissing::for($driver->name(), GatewayCapability::RefundStatus)->getMessage())
            ->toContain('sslcommerz')
            ->toContain('refund status');
    });

    it('says which fields a provider still needs, never their values', function () {
        $sslcommerz = collect(app(PaymentGatewayManager::class)->catalogue())
            ->firstWhere('name', 'sslcommerz');

        expect($sslcommerz['required_configuration'])->toBe(['store_id', 'store_password'])
            ->and($sslcommerz['missing_configuration'])->toBe(['store_id', 'store_password']);

        gatewayTestCredentials();

        $refreshed = collect(app(PaymentGatewayManager::class)->catalogue())
            ->firstWhere('name', 'sslcommerz');

        expect($refreshed['missing_configuration'])->toBe([]);
    });

    it('offers no gateway for a currency none of them accepts', function () {
        gatewayTestCredentials();

        expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->toBe(['sslcommerz'])
            ->and(app(PaymentGatewayManager::class)->availableFor(Currency::USD))->toBe([]);
    });
});
