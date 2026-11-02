<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\ConfigurePartnerGatewayAvailability;
use App\Domain\Billing\Actions\ProcessRefund;
use App\Domain\Billing\Actions\RequestRefund;
use App\Domain\Billing\Actions\SavePartnerGateway;
use App\Domain\Billing\Actions\UsePlatformGateway;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Exceptions\PartnerGatewayRefused;
use App\Domain\Billing\Exceptions\RefundRefused;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Billing\PartnerGatewayResolver;
use App\Domain\Billing\Policies\PartnerGatewayPolicy;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\Gateways\ScopedGatewaySettings;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A Partner's own payment gateway (D36, Model B): who may set it, how its
 * credentials are kept, and that a payment stays bound to the version it began with.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Feriwala's own SSLCommerz credentials, so a Partner's can be told apart.
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'platform-store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'platform-pass', isEncrypted: true);

    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->owner = $this->account->owner;
    $this->website = Website::factory()->active()->forAccount($this->account)->create();
});

/**
 * Admin switches Partner gateways on for SSLCommerz.
 */
function partnerGatewayAllow(User $admin, array $providers = ['sslcommerz'], bool $enabled = true): void
{
    app(ConfigurePartnerGatewayAvailability::class)->handle($admin, $enabled, $providers);
}

/**
 * @param  array<string, string|null>  $credentials
 */
function partnerGatewaySave(User $actor, Website $website, string $mode = 'sandbox', array $credentials = ['store_id' => 'partner-store', 'store_password' => 'partner-secret-pass']): PartnerGatewayConfigurationVersion
{
    return app(SavePartnerGateway::class)->handle($actor, $website, 'sslcommerz', $mode, $credentials);
}

function partnerGatewayPayment(Website $website, PartnerGatewayConfigurationVersion $version, array $overrides = []): Payment
{
    return Payment::create([
        'business_account_id' => $website->business_account_id,
        'purpose' => PaymentPurpose::WebsiteOrder,
        'status' => PaymentStatus::Paid,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('1300.00', Currency::BDT),
        'gateway' => 'sslcommerz',
        'gateway_reference' => 'val-partner-1',
        'gateway_mode' => 'sandbox',
        'gateway_ownership' => GatewayOwnership::Partner,
        'funds_destination' => 'partner',
        'website_id' => $website->id,
        'partner_gateway_configuration_version_id' => $version->id,
        'completed_at' => now(),
        ...$overrides,
    ]);
}

function partnerGatewayCredentialOf(object $driver): string
{
    $property = new ReflectionProperty($driver, 'credentials');

    return $property->getValue($driver)->storeId();
}

describe('what Admin allows', function () {
    it('keeps Partner gateways off until Admin turns them on', function () {
        expect(fn () => partnerGatewaySave($this->owner, $this->website))
            ->toThrow(PartnerGatewayRefused::class, 'not switched on');
    });

    it('refuses a provider that is not on the list', function () {
        partnerGatewayAllow($this->admin, ['sslcommerz']);

        expect(fn () => app(SavePartnerGateway::class)->handle($this->owner, $this->website, 'stripe', 'sandbox', []))
            ->toThrow(PartnerGatewayRefused::class, 'not a gateway Partners may use');
    });

    it('only lets a provider with server-side verification be offered', function () {
        // Nagad declares no capabilities, so it cannot take a payment safely.
        expect(fn () => partnerGatewayAllow($this->admin, ['nagad']))
            ->toThrow(PartnerGatewayRefused::class);
    });

    it('lets only someone holding the gateway permission change the allow-list', function () {
        $partnerOwner = $this->owner;

        expect(fn () => app(ConfigurePartnerGatewayAvailability::class)->handle($partnerOwner, true, ['sslcommerz']))
            ->toThrow(AuthorizationException::class);

        $finance = testPlatformStaff(PlatformRole::FinanceManager);

        expect($finance->can('payment.manage_integrations'))->toBeFalse()
            ->and(fn () => app(ConfigurePartnerGatewayAvailability::class)->handle($finance, true, ['sslcommerz']))
            ->toThrow(AuthorizationException::class);
    });

    it('audits the switch without anything secret', function () {
        partnerGatewayAllow($this->admin);

        $entry = AuditLog::query()->where('action', 'payment.partner_gateways_availability_changed')->first();

        expect($entry)->not->toBeNull()
            ->and($entry->after)->toMatchArray(['enabled' => true, 'providers' => ['sslcommerz']]);
    });

    it('stops new configuration when Admin switches the feature off again', function () {
        partnerGatewayAllow($this->admin);
        partnerGatewaySave($this->owner, $this->website);

        partnerGatewayAllow($this->admin, ['sslcommerz'], enabled: false);

        expect(fn () => partnerGatewaySave($this->owner, $this->website, credentials: ['store_id' => 'other']))
            ->toThrow(PartnerGatewayRefused::class)
            ->and(app(PartnerGatewayResolver::class)->activeVersionFor($this->website))->toBeNull();
    });
});

describe('saving a Partner gateway', function () {
    beforeEach(function () {
        partnerGatewayAllow($this->admin);
    });

    it('writes an encrypted version and never keeps a secret in the clear', function () {
        $version = partnerGatewaySave($this->owner, $this->website);

        $raw = DB::table('partner_gateway_configuration_versions')->where('id', $version->id)->value('credentials');

        expect($raw)->not->toContain('partner-secret-pass')
            ->and($raw)->not->toContain('partner-store')
            ->and($version->fresh()->credentials)->toBe(['store_id' => 'partner-store', 'store_password' => 'partner-secret-pass'])
            ->and($version->version)->toBe(1)
            ->and($version->mode)->toBe('sandbox');
    });

    it('never serialises the credentials', function () {
        $version = partnerGatewaySave($this->owner, $this->website);

        expect($version->toJson())->not->toContain('partner-secret-pass')
            ->and($version->toArray())->not->toHaveKey('credentials')
            ->and($version->presentKeys())->toBe(['store_id', 'store_password']);
    });

    it('keeps secrets out of the audit log', function () {
        partnerGatewaySave($this->owner, $this->website);

        $audit = AuditLog::query()->get()->toJson();

        expect($audit)->toContain('payment.partner_gateway_configured')
            ->and($audit)->not->toContain('partner-secret-pass')
            ->and($audit)->not->toContain('partner-store');
    });

    it('keeps secrets out of a refusal message', function () {
        try {
            partnerGatewaySave($this->owner, $this->website, credentials: ['store_id' => 'partner-store', 'store_password' => '']);
            $this->fail('An incomplete set should have been refused.');
        } catch (PartnerGatewayRefused $refused) {
            expect($refused->getMessage())->toContain('store password')
                ->and($refused->getMessage())->not->toContain('partner-store');
        }
    });

    it('writes a new version on every save and keeps the old one', function () {
        $first = partnerGatewaySave($this->owner, $this->website);
        $second = partnerGatewaySave($this->owner, $this->website, credentials: ['store_id' => 'partner-store-2', 'store_password' => '']);

        expect($second->version)->toBe(2)
            // A blank field keeps what was there.
            ->and($second->credentials)->toBe(['store_id' => 'partner-store-2', 'store_password' => 'partner-secret-pass'])
            ->and($first->fresh()->credentials['store_id'])->toBe('partner-store');
    });

    it('needs every credential again when the mode changes', function () {
        partnerGatewaySave($this->owner, $this->website);

        expect(fn () => partnerGatewaySave($this->owner, $this->website, mode: 'live', credentials: ['store_id' => 'live-store', 'store_password' => '']))
            ->toThrow(PartnerGatewayRefused::class, 'every credential entered again');

        $live = partnerGatewaySave($this->owner, $this->website, mode: 'live', credentials: ['store_id' => 'live-store', 'store_password' => 'live-pass']);

        expect($live->mode)->toBe('live');
    });

    it('refuses an unknown mode', function () {
        expect(fn () => partnerGatewaySave($this->owner, $this->website, mode: 'staging'))
            ->toThrow(PartnerGatewayRefused::class);
    });

    it('does not store a credential the provider does not declare', function () {
        $version = partnerGatewaySave($this->owner, $this->website, credentials: [
            'store_id' => 'partner-store', 'store_password' => 'partner-pass', 'admin_override' => 'x',
        ]);

        expect(array_keys($version->credentials))->toBe(['store_id', 'store_password']);
    });

    it('keeps one configuration per Storefront however often it is saved', function () {
        partnerGatewaySave($this->owner, $this->website);
        partnerGatewaySave($this->owner, $this->website, credentials: ['store_id' => 'again']);

        expect(PartnerGatewayConfiguration::query()->where('website_id', $this->website->id)->count())->toBe(1);
    });
});

describe('whose gateway it is', function () {
    beforeEach(function () {
        partnerGatewayAllow($this->admin);
    });

    it('refuses another Partner configuring this Storefront', function () {
        $other = testBusinessAccount(AccountStatus::Active);

        expect(fn () => partnerGatewaySave($other->owner, $this->website))
            ->toThrow(AuthorizationException::class)
            ->and(PartnerGatewayConfiguration::query()->count())->toBe(0);
    });

    it('refuses another Partner switching this Storefront back', function () {
        partnerGatewaySave($this->owner, $this->website);
        $other = testBusinessAccount(AccountStatus::Active);

        expect(fn () => app(UsePlatformGateway::class)->handle($other->owner, $this->website))
            ->toThrow(AuthorizationException::class)
            ->and(PartnerGatewayConfiguration::query()->first()->isActive())->toBeTrue();
    });

    it('refuses a Super Admin, who holds every permission', function () {
        expect(fn () => partnerGatewaySave($this->admin, $this->website))
            ->toThrow(AuthorizationException::class)
            ->and(PartnerGatewayPolicy::canConfigure($this->admin, $this->website))->toBeFalse();
    });

    it('lets staff see that a Storefront has a gateway but never configure it', function () {
        $finance = testPlatformStaff(PlatformRole::FinanceManager);

        expect($finance->can('payment.view'))->toBeTrue()
            ->and(PartnerGatewayPolicy::canViewStatus($finance, $this->website))->toBeTrue()
            ->and(PartnerGatewayPolicy::canConfigure($finance, $this->website))->toBeFalse();
    });

    it('lets a Partner see only their own Storefront status', function () {
        $other = testBusinessAccount(AccountStatus::Active);

        expect(PartnerGatewayPolicy::canViewStatus($this->owner, $this->website))->toBeTrue()
            ->and(PartnerGatewayPolicy::canViewStatus($other->owner, $this->website))->toBeFalse();
    });

    it('offers the Platform gateway again once the Partner switches back', function () {
        $version = partnerGatewaySave($this->owner, $this->website);

        expect(app(PartnerGatewayResolver::class)->activeVersionFor($this->website)?->id)->toBe($version->id);

        app(UsePlatformGateway::class)->handle($this->owner, $this->website);

        expect(app(PartnerGatewayResolver::class)->activeVersionFor($this->website))->toBeNull()
            ->and(AuditLog::query()->where('action', 'payment.partner_gateway_disabled')->count())->toBe(1);
    });
});

describe('a payment stays with the credentials it began with', function () {
    beforeEach(function () {
        partnerGatewayAllow($this->admin);
    });

    it('resolves the frozen version after the Partner saves a newer one', function () {
        $first = partnerGatewaySave($this->owner, $this->website);
        $payment = partnerGatewayPayment($this->website, $first);

        partnerGatewaySave($this->owner, $this->website, credentials: ['store_id' => 'rotated-store', 'store_password' => 'rotated-pass']);
        app(UsePlatformGateway::class)->handle($this->owner, $this->website);

        $driver = app(PartnerGatewayResolver::class)->driverForPayment($payment->fresh());

        expect(partnerGatewayCredentialOf($driver))->toBe('partner-store')
            ->and(app(PartnerGatewayResolver::class)->activeVersionFor($this->website))->toBeNull();
    });

    it('never gives a Partner driver Feriwala\'s credentials, nor the reverse', function () {
        $version = partnerGatewaySave($this->owner, $this->website);
        $partnerDriver = app(PartnerGatewayResolver::class)->driverForPayment(partnerGatewayPayment($this->website, $version));
        $platformDriver = app(PaymentGatewayManager::class)->driver('sslcommerz');

        expect(partnerGatewayCredentialOf($partnerDriver))->toBe('partner-store')
            ->and(partnerGatewayCredentialOf($platformDriver))->toBe('platform-store');
    });

    it('does not remember a Partner driver in the shared manager', function () {
        $version = partnerGatewaySave($this->owner, $this->website);
        app(PartnerGatewayResolver::class)->driverForPayment(partnerGatewayPayment($this->website, $version));

        expect(partnerGatewayCredentialOf(app(PaymentGatewayManager::class)->driver('sslcommerz')))->toBe('platform-store');
    });

    it('refuses to give a platform payment a Partner gateway', function () {
        $payment = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WebsiteOrder,
            'status' => PaymentStatus::Paid,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('100.00', Currency::BDT),
            'gateway' => 'sslcommerz',
            'gateway_ownership' => GatewayOwnership::Platform,
            'funds_destination' => 'platform',
            'website_id' => $this->website->id,
        ]);

        expect(fn () => app(PartnerGatewayResolver::class)->driverForPayment($payment))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a version that belongs to another Storefront', function () {
        $first = partnerGatewaySave($this->owner, $this->website);
        $otherWebsite = Website::factory()->active()->forAccount($this->account)->create();
        $payment = partnerGatewayPayment($otherWebsite, $first);

        expect(fn () => app(PartnerGatewayResolver::class)->driverForPayment($payment))
            ->toThrow(Exception::class);
    });

    it('cannot change a payment\'s ownership once it is written', function () {
        $version = partnerGatewaySave($this->owner, $this->website);
        $payment = partnerGatewayPayment($this->website, $version);

        foreach (['gateway_ownership' => 'platform', 'funds_destination' => 'platform', 'website_id' => null] as $column => $value) {
            expect(fn () => DB::transaction(fn () => DB::table('payments')->where('id', $payment->id)->update([$column => $value])))
                ->toThrow(QueryException::class);
        }

        expect($payment->fresh()->gateway_ownership)->toBe(GatewayOwnership::Partner);
    });

    it('refuses a Partner payment with no version to settle it with', function () {
        expect(fn () => DB::transaction(fn () => Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WebsiteOrder,
            'status' => PaymentStatus::Initiated,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('100.00', Currency::BDT),
            'gateway' => 'sslcommerz',
            'gateway_ownership' => GatewayOwnership::Partner,
            'funds_destination' => 'partner',
            'website_id' => $this->website->id,
        ])))->toThrow(QueryException::class);
    });

    it('keeps saved versions immutable', function () {
        $version = partnerGatewaySave($this->owner, $this->website);

        expect(fn () => DB::transaction(fn () => DB::table('partner_gateway_configuration_versions')->where('id', $version->id)->update(['mode' => 'live'])))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('partner_gateway_configuration_versions')->where('id', $version->id)->delete()))
            ->toThrow(QueryException::class);
    });

    it('answers a gateway driver from a scoped version without touching settings', function () {
        $settings = new ScopedGatewaySettings('sslcommerz', 'live', ['store_id' => 'x', 'store_password' => 'y']);

        expect($settings->get('payment.sslcommerz.mode'))->toBe('live')
            ->and($settings->get('payment.sslcommerz.live.store_id'))->toBe('x')
            ->and($settings->get('payment.sslcommerz.sandbox.store_id'))->toBeNull()
            ->and(fn () => $settings->set('payment.sslcommerz.mode', 'sandbox'))->toThrow(LogicException::class);
    });
});

describe('refunds of money Feriwala never held', function () {
    beforeEach(function () {
        partnerGatewayAllow($this->admin);
        $this->version = partnerGatewaySave($this->owner, $this->website);
        $this->partnerPayment = partnerGatewayPayment($this->website, $this->version);
        $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
    });

    it('refuses to send a refund of a Partner-owned payment through Feriwala\'s gateway', function () {
        $refund = RefundRequest::factory()->status(RefundStatus::Approved)->create([
            'payment_id' => $this->partnerPayment->id,
            'business_account_id' => $this->account->id,
            'allocation_type' => AllocationType::WebsiteGoods,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('100.00', Currency::BDT),
        ]);

        expect(fn () => app(ProcessRefund::class)->handle($refund, $this->manager, true, true))
            ->toThrow(RefundRefused::class, 'Partner\'s own gateway');

        expect($refund->fresh()->status)->toBe(RefundStatus::Approved);
    });

    it('refuses to open a refund request against a Partner-owned payment', function () {
        expect(fn () => app(RequestRefund::class)->handle($this->partnerPayment, AllocationType::WebsiteGoods, $this->manager, 'Customer asked'))
            ->toThrow(InvalidArgumentException::class, 'Partner\'s own gateway')
            ->and(RefundRequest::query()->count())->toBe(0);
    });

    it('leaves a payment with no ownership exactly as it was', function () {
        $legacy = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WebsiteOrder,
            'status' => PaymentStatus::Paid,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('100.00', Currency::BDT),
            'gateway' => 'sslcommerz',
        ]);

        expect($legacy->fresh()->gateway_ownership)->toBeNull()
            ->and($legacy->isPartnerOwned())->toBeFalse();
    });
});
