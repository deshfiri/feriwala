<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * The subscription, renewal and package change on mobile (§8.2, §8.3, §8.4):
 * the same planner, quotes and payment Actions as the web, reached only through
 * the signed-in person's own account, and open throughout the funnel.
 */

beforeEach(function () {
    $this->account = testAccountWithPackageFeatures([], AccountStatus::Active);
    $this->owner = $this->account->owner;
    $this->term = UserPackage::query()->where('business_account_id', $this->account->id)->firstOrFail();
});

function mobilePackagesGateway(): void
{
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.example/redirect'])]);
}

function mobilePackagesTarget(string $fee = '8000.00'): Package
{
    return Package::create([
        'slug' => 'target-'.Str::lower(Str::random(6)),
        'name' => 'Bigger package',
        'fee' => Money::fromDecimal($fee),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function mobilePackagesRenewable(UserPackage $term): void
{
    $term->forceFill(['expires_at' => now()->addDays(5)])->save();
}

describe('the subscription', function () {
    test('shows the current term with its captured terms and whether it can be renewed', function () {
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.show'))
            ->assertOk()
            ->assertJsonPath('current.id', $this->term->public_id)
            ->assertJsonPath('current.package', 'Test package')
            ->assertJsonPath('current.status', 'active')
            ->assertJsonPath('current.paid.amount', '5000.00')
            ->assertJsonPath('can.renew', false)
            ->assertJsonStructure(['history', 'renewal_blocker']);
    });

    test('says it can be renewed once the term is inside the renewal window', function () {
        mobilePackagesRenewable($this->term);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.show'))
            ->assertOk()
            ->assertJsonPath('can.renew', true)
            ->assertJsonPath('renewal_blocker', null);
    });

    test('is still open to an account that has not been activated', function () {
        $pending = testAccountWithPackageFeatures([], AccountStatus::PaymentVerificationPending);
        Sanctum::actingAs($pending->owner);

        $this->getJson(route('mobile.v1.packages.subscription.show'))->assertOk();
    });

    test('is cancelled with a reason, and the term stops', function () {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.packages.subscription.cancel'), ['reason' => 'Closing the business'])
            ->assertOk()
            ->assertJsonPath('can.renew', false);

        expect($this->term->refresh()->status)->toBe(UserPackageStatus::Cancelled);
    });

    test('cannot be cancelled without a reason or with nothing to cancel', function () {
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.packages.subscription.cancel'), ['reason' => 'no'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->term->forceFill(['status' => UserPackageStatus::Cancelled])->save();

        $this->postJson(route('mobile.v1.packages.subscription.cancel'), ['reason' => 'Closing the business'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    });

    test('never reaches another account\'s term: each person sees only their own', function () {
        $other = testAccountWithPackageFeatures([], AccountStatus::Active);
        $theirs = UserPackage::query()->where('business_account_id', $other->id)->firstOrFail();

        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.show'))
            ->assertOk()
            ->assertJsonPath('current.id', $this->term->public_id)
            ->assertJsonMissing(['id' => $theirs->public_id]);

        $this->postJson(route('mobile.v1.packages.subscription.cancel'), ['reason' => 'Closing the business'])->assertOk();

        expect($theirs->refresh()->status)->toBe(UserPackageStatus::Active);
    });

    test('a login with no business account has no subscription', function () {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson(route('mobile.v1.packages.subscription.show'))->assertForbidden();
    });

    test('needs a token', function () {
        $this->getJson(route('mobile.v1.packages.subscription.show'))->assertUnauthorized();
        $this->postJson(route('mobile.v1.packages.subscription.cancel'))->assertUnauthorized();
        $this->getJson(route('mobile.v1.packages.subscription.renew.show'))->assertUnauthorized();
        $this->postJson(route('mobile.v1.packages.subscription.renew.pay'))->assertUnauthorized();
        $this->getJson(route('mobile.v1.packages.subscription.change.index'))->assertUnauthorized();
        $this->postJson(route('mobile.v1.packages.subscription.change.store'))->assertUnauthorized();
    });
});

describe('renewal', function () {
    test('opens the renewal and quotes it from the captured terms', function () {
        mobilePackagesRenewable($this->term);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.renew.show'))
            ->assertOk()
            ->assertJsonPath('renewal.package', 'Test package')
            ->assertJsonPath('current.id', $this->term->public_id)
            ->assertJsonStructure(['renewal' => ['id', 'starts_at'], 'quote', 'gateways']);

        expect(UserPackage::query()->where('business_account_id', $this->account->id)->where('status', UserPackageStatus::PendingPayment)->count())->toBe(1);
    });

    test('is turned back with the reason while the term is not yet renewable', function () {
        mobilePackagesGateway();
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.renew.show'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'renewal_unavailable');

        $this->postJson(route('mobile.v1.packages.subscription.renew.pay'), ['gateway' => 'sslcommerz'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'renewal_unavailable');
    });

    test('paying records one payment for the renewal and hands back the gateway address', function () {
        mobilePackagesGateway();
        mobilePackagesRenewable($this->term);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.renew.show'))->assertOk();

        $first = $this->postJson(route('mobile.v1.packages.subscription.renew.pay'), ['gateway' => 'sslcommerz'])
            ->assertOk()
            ->assertJsonPath('redirect_url', 'https://sandbox.example/redirect');

        $this->postJson(route('mobile.v1.packages.subscription.renew.pay'), ['gateway' => 'sslcommerz'])
            ->assertOk()
            ->assertJsonPath('payment_id', $first->json('payment_id'));

        $payments = Payment::query()->where('business_account_id', $this->account->id)->where('purpose', PaymentPurpose::PackageRenewal)->get();

        expect($payments)->toHaveCount(1);
    });

    test('a gateway that is not enabled is refused', function () {
        mobilePackagesGateway();
        mobilePackagesRenewable($this->term);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.packages.subscription.renew.pay'), ['gateway' => 'nowhere'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gateway');
    });
});

describe('package change', function () {
    test('lists every other package with what moving to it would do', function () {
        $target = mobilePackagesTarget();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson(route('mobile.v1.packages.subscription.change.index'))
            ->assertOk()
            ->assertJsonPath('current.package', 'Test package')
            ->assertJsonStructure(['options', 'gateways']);

        expect(collect($response->json('options'))->pluck('slug'))->toContain($target->slug)->not->toContain($this->term->package->slug);
    });

    test('has nothing to change without a running term', function () {
        $this->term->forceFill(['status' => UserPackageStatus::Cancelled])->save();
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.packages.subscription.change.index'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_current_package');
    });

    test('opens the change and hands back the gateway address', function () {
        mobilePackagesGateway();
        $target = mobilePackagesTarget();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.packages.subscription.change.store'), ['package' => $target->slug, 'gateway' => 'sslcommerz'])
            ->assertOk()
            ->assertJsonPath('redirect_url', 'https://sandbox.example/redirect');

        expect(Payment::query()->where('business_account_id', $this->account->id)->whereIn('purpose', [PaymentPurpose::PackageUpgrade, PaymentPurpose::PackageDowngrade])->count())->toBe(1);
    });

    test('an unknown package or a gateway that is not enabled is refused', function () {
        mobilePackagesGateway();
        $target = mobilePackagesTarget();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.packages.subscription.change.store'), ['package' => 'no-such-package', 'gateway' => 'sslcommerz'])
            ->assertNotFound();

        $this->postJson(route('mobile.v1.packages.subscription.change.store'), ['package' => $target->slug, 'gateway' => 'nowhere'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gateway');
    });
});
