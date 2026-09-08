<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Test Groups
|--------------------------------------------------------------------------
|
| Groups let a slice be run on its own — `pest --group=wallet` while working on
| the ledger, rather than the whole suite. They are declared here so the names
| stay consistent instead of being invented per file.
|
| Several exist because requirements.txt §43 names them explicitly and they are
| the ones most likely to be skipped otherwise: concurrency, self-scoped data
| access, and the product creation restrictions of §12.
|
*/

pest()->group('onboarding')->in('Feature/Onboarding');
pest()->group('kyc')->in('Feature/Kyc');
pest()->group('package')->in('Feature/Package');
pest()->group('payment')->in('Feature/Payment');
pest()->group('tax')->in('Feature/Tax');
pest()->group('wallet')->in('Feature/Wallet');
pest()->group('ledger')->in('Feature/Ledger');
pest()->group('commission')->in('Feature/Commission');
pest()->group('referral')->in('Feature/Referral');
pest()->group('withdrawal')->in('Feature/Withdrawal');
pest()->group('catalog')->in('Feature/Catalog');
pest()->group('product-restrictions')->in('Feature/ProductRestrictions');
pest()->group('inventory')->in('Feature/Inventory');
pest()->group('wholesale')->in('Feature/Wholesale');
pest()->group('dropshipping')->in('Feature/Dropshipping');
pest()->group('website-api')->in('Feature/WebsiteApi');
pest()->group('orders')->in('Feature/Orders');
pest()->group('fulfillment')->in('Feature/Fulfillment');
pest()->group('courier')->in('Feature/Courier');
pest()->group('settlement')->in('Feature/Settlement');
pest()->group('reports')->in('Feature/Reports');
pest()->group('permissions')->in('Feature/Permissions');
pest()->group('self-scope')->in('Feature/SelfScope');
pest()->group('security')->in('Feature/Security');
pest()->group('concurrency')->in('Feature/Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * Shared fixtures for the identity / business-account split (D23).
 *
 * These live here rather than in one test file because Pest loads every test
 * into a single global function namespace: a helper defined in one file and
 * called from another works only while both happen to be loaded, which breaks
 * the moment somebody runs that second file on its own.
 *
 * Named for what they build, not for the module that happens to use them first.
 */

/**
 * A business account sitting at `$status`, with an owner and a membership.
 *
 * Reach the person through `$account->owner`. The commercial lifecycle belongs
 * to the account, so tests about trading build one of these; tests about a
 * login build a `User` and give it no account at all.
 */
function testBusinessAccount(?AccountStatus $status = null): BusinessAccount
{
    $factory = BusinessAccount::factory();

    return ($status === null ? $factory : $factory->onboarding($status))->create();
}

/**
 * An account that has cleared every §5.1 condition and is waiting on us.
 *
 * Built through the real orchestration rather than by setting the columns:
 * only the clock is moved, so ordering tests exercise the column the
 * application actually writes.
 */
function testAccountReadyForActivation(int $readyDaysAgo = 0): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);

    KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now()->subDays($readyDaysAgo + 1),
    ]);

    Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'amount_minor' => 600000,
        'currency_code' => 'BDT',
        'completed_at' => now()->subDays($readyDaysAgo),
    ]);

    app(EvaluateActivationReadiness::class)->handle($account);

    if ($readyDaysAgo > 0) {
        $account->forceFill(['approval_pending_at' => now()->subDays($readyDaysAgo)])->save();
    }

    return $account->refresh();
}

/**
 * A Feriwala staff member: an active login holding `$role`, and **no business
 * account** — which is the whole point of D23.
 */
/**
 * A password the §6 policy accepts: twelve characters, mixed case, a digit and
 * a symbol.
 *
 * The factory's 'password' is fine for signing *in*, which is not
 * strength-checked. Anything that writes a password needs this, and needs it
 * from one place — a literal per test file is a policy change that has to be
 * chased through thirty payloads.
 */
function testStrongPassword(): string
{
    return 'Feriwala!Test#2026';
}

function testPlatformStaff(PlatformRole $role): User
{
    $user = User::factory()->staff()->create();
    $user->assignRole($role->value);

    return $user;
}

/**
 * An active account on a package that allows `$staffLimit` staff (§8.1).
 *
 * Null means the package sets no cap; zero means it grants no staff at all —
 * two different answers that must not be conflated, which is why the fixture
 * writes the feature row for both rather than leaving one absent.
 *
 * Built through real package rows rather than by stubbing Entitlements: the
 * limit is read from a subscription in the tests exactly as it is in production,
 * so a package that has lapsed stops granting staff without anything else being
 * told about it.
 */
function testAccountWithStaffLimit(?int $staffLimit, ?AccountStatus $status = null): BusinessAccount
{
    $account = $status === null
        ? BusinessAccount::factory()->create()
        : BusinessAccount::factory()->onboarding($status)->create();

    $package = Package::create([
        'slug' => 'staff-limit-'.Str::lower(Str::random(8)),
        'name' => 'Test package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    // The row is always written, including for unlimited. A **missing** row is
    // not "unlimited" — PackageFeature::default() makes it zero, so that a
    // package which forgets a feature under-delivers rather than handing out
    // free staff. Unlimited is a row whose value is null.
    $package->features()->create([
        'feature' => PackageFeature::StaffLimit->value,
        'value' => $staffLimit === null ? null : (string) $staffLimit,
    ]);

    $userPackage = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'paid_fee_minor' => 500000,
        'currency_code' => 'BDT',
    ]);

    $account->forceFill(['current_user_package_id' => $userPackage->id])->save();

    return $account->refresh();
}
