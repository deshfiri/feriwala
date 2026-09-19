<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Referral\Actions\AttachReferrer;
use App\Domain\Referral\Actions\OpenReferralPlan;
use App\Domain\Referral\Data\ReferralPlanDraft;
use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\CommissionBase;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Enums\RewardType;
use App\Domain\Referral\Models\ReferralPlan;
use App\Domain\Referral\ReferralSettings;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\Actions\ManageWebsiteCredentials;
use App\Domain\Website\Api\RequestSignature;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
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
pest()->group('website')->in('Feature/Website');
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
    $factory = User::factory()->staff();

    /*
     * §36 requires a second factor for sensitive roles and the admin panel
     * enforces it, so a fixture holding one of those roles has to have one —
     * otherwise every administration test quietly becomes a test of the
     * enrolment redirect. A test that is *about* the requirement builds its own
     * staff without it; see tests/Feature/Auth/TwoFactorRequirementTest.php.
     */
    if ($role->requiresTwoFactor()) {
        $factory = $factory->withTwoFactor();
    }

    $user = $factory->create();
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
/**
 * An active account on a package granting the features it is handed (§8.1, §16).
 *
 * Built the same way as the staff fixture and for the same reason: the
 * entitlement is read from a real subscription, so a package that lapses stops
 * granting a website without anything else being told about it.
 *
 * Every feature is written as a row, including the limits — a **missing** row
 * is zero, not unlimited, and a website fixture that relied on absence would be
 * testing the default rather than the package.
 *
 * @param  array<string, int|string|null>  $features  keyed by PackageFeature value; null means unlimited
 */
function testAccountWithPackageFeatures(array $features, ?AccountStatus $status = null): BusinessAccount
{
    $account = $status === null
        ? BusinessAccount::factory()->create()
        : BusinessAccount::factory()->onboarding($status)->create();

    $package = Package::create([
        'slug' => 'features-'.Str::lower(Str::random(8)),
        'name' => 'Test package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    foreach ($features as $feature => $value) {
        $package->features()->create([
            'feature' => $feature,
            'value' => $value === null ? null : (string) $value,
        ]);
    }

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

/**
 * An active account whose package includes `$limit` dedicated websites (§16).
 *
 * Here rather than in one website test file because Pest loads every test into
 * one global namespace: a helper defined in one file and called from another
 * works only while both happen to be loaded.
 *
 * @param  array<string, string|null>  $extra  further package features
 */
function websiteTestAccount(?int $limit = 1, bool $entitled = true, array $extra = []): BusinessAccount
{
    return testAccountWithPackageFeatures([
        PackageFeature::DedicatedWebsite->value => $entitled ? '1' : '0',
        PackageFeature::WebsiteLimit->value => $limit === null ? null : (string) $limit,
        ...$extra,
    ], AccountStatus::Active);
}

/**
 * What Feriwala charges for one of the website services, from today.
 */
function websiteTestFee(FeeType $type, int $minorUnits): FeeRule
{
    return FeeRule::create([
        'fee_type' => $type->value,
        'amount_minor' => $minorUnits,
        'currency_code' => 'BDT',
        'effective_from' => now()->subDay(),
    ]);
}

/**
 * An open wallet holding `$credit` for the account.
 */
function websiteTestWallet(BusinessAccount $account, int $credit): Wallet
{
    $wallet = app(OpenWallet::class)->handle($account);

    if ($credit > 0) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of($credit, Currency::BDT),
            new PostingContext(source: 'test', description: 'Opening'),
        );
    }

    return $wallet->refresh();
}

/**
 * An active dropshipping product in an available category, offered to every
 * package and account, with selling bounds of 2,000–4,000 taka and 2,500
 * suggested (§11.1).
 *
 * @param  array<string, mixed>  $attributes
 */
function websiteTestProduct(array $attributes = []): Product
{
    $category = Category::query()->where('is_active', true)->whereNull('parent_id')->first()
        ?? Category::create([
            'name' => 'Clothing',
            'slug' => 'clothing-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

    return Product::create([
        'name' => 'Cotton panjabi',
        'slug' => 'cotton-panjabi-'.Str::lower(Str::random(6)),
        'sku' => 'FW-'.Str::upper(Str::random(6)),
        'category_id' => $category->id,
        'brand_id' => null,
        'status' => ProductStatus::Active,
        'currency_code' => 'BDT',
        'wholesale_price_minor' => 150000,
        'minimum_selling_price_minor' => 200000,
        'maximum_selling_price_minor' => 400000,
        'suggested_selling_price_minor' => 250000,
        'dropshipping_status' => ProductStatus::DropshippingEnabled,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
        'account_scope' => AccountScope::AnyAccount,
        ...$attributes,
    ]);
}

/**
 * A storefront API call, signed the way the contract says (contract §3.2).
 *
 * Over HTTPS, because plain HTTP is refused. Every part of the signature can be
 * overridden, so a test about a bad timestamp or a replayed nonce changes only
 * that part and nothing else.
 *
 * @param  array<string, mixed>  $query
 * @param  array{timestamp?: string, nonce?: string, secret?: string, key_id?: string, authorization?: string|null, https?: bool}  $overrides
 * @return TestResponse<Response>
 */
function storefrontCall(
    WebsiteCredential $credential,
    string $secret,
    string $path,
    array $query = [],
    string $method = 'GET',
    string $body = '',
    array $overrides = [],
): TestResponse {
    $timestamp = $overrides['timestamp'] ?? (string) now()->getTimestamp();
    $nonce = $overrides['nonce'] ?? (string) Str::uuid();
    $fullPath = '/api/storefront/v1/'.ltrim($path, '/');
    $rawQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $signature = RequestSignature::sign(
        $overrides['secret'] ?? $secret,
        RequestSignature::canonical($method, $fullPath, $rawQuery, $timestamp, $nonce, $body),
    );

    $headers = [
        'X-Feriwala-Timestamp' => $timestamp,
        'X-Feriwala-Nonce' => $nonce,
        'Accept' => 'application/json',
    ];

    $authorization = array_key_exists('authorization', $overrides)
        ? $overrides['authorization']
        : RequestSignature::authorization($overrides['key_id'] ?? $credential->key_id, $signature);

    if ($authorization !== null) {
        $headers['Authorization'] = $authorization;
    }

    $scheme = ($overrides['https'] ?? true) ? 'https' : 'http';
    $url = $scheme.'://localhost'.$fullPath.($rawQuery === '' ? '' : '?'.$rawQuery);

    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call($method, $url, [], [], [], $server, $body === '' ? null : $body);
}

/**
 * A credential for a website, with its plaintext secret.
 *
 * @param  array<int, CredentialScope>  $scopes
 * @return array{0: WebsiteCredential, 1: string}
 */
function storefrontCredential(Website $website, array $scopes = []): array
{
    $issued = app(ManageWebsiteCredentials::class)->issue(
        $website,
        $website->businessAccount->owner,
        'Storefront',
        $scopes === [] ? [CredentialScope::CatalogRead, CredentialScope::InventoryRead] : $scopes,
    );

    return [$issued->credential, $issued->secret];
}

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

/**
 * A multi-level plan version in force from an hour ago (D24).
 *
 * Each level is `[type, value]` or `[type, value, cap]`: a fixed amount in
 * minor units, or a percentage as text (`'10'`, `'2.5'`). Anything else a
 * test needs to vary goes in `$overrides`, keyed as the draft names it.
 *
 * @param  list<array{0: string, 1: int|string, 2?: int|null}>  $levels
 * @param  array<string, mixed>  $overrides
 */
function referralTestPlan(array $levels, array $overrides = []): ReferralPlan
{
    $rule = fn (array $level) => $level[0] === 'fixed'
        ? new RewardRule(RewardType::Fixed, amountMinor: (int) $level[1], capMinor: $level[2] ?? null)
        : new RewardRule(RewardType::Percentage, rateBps: RewardRule::basisPointsFromPercent((string) $level[1]), capMinor: $level[2] ?? null);

    $draft = [
        'packageId' => null,
        'trigger' => ReferralTrigger::AccountActivation,
        'base' => CommissionBase::ActivationFees,
        'maxDepth' => count($levels),
        'levels' => array_map(fn (array $level, int $index) => [
            'level' => $index + 1,
            'rule' => $rule($level),
            'enabled' => true,
            'required_package_ids' => [],
            'min_active_direct_referrals' => 0,
        ], $levels, array_keys($levels)),
        'joiningReward' => null,
        'holdingDays' => 0,
        'minimumQualifyingPaymentMinor' => 0,
        'qualifiesSuspended' => false,
        'qualifiesRestricted' => false,
        'qualifiesPackageLapsed' => false,
        'qualifiesNotActive' => false,
        'effectiveFrom' => now()->subHour()->toImmutable(),
        'reason' => 'Plan for a test.',
        ...$overrides,
    ];

    return app(OpenReferralPlan::class)->handle(new ReferralPlanDraft(...$draft), User::factory()->create());
}

/**
 * `$count` active accounts, each referred by the one before: `[0]` is the top.
 *
 * @return list<BusinessAccount>
 */
function referralTestChain(int $count): array
{
    $accounts = [];

    for ($i = 0; $i < $count; $i++) {
        $account = testBusinessAccount(AccountStatus::Active);

        if ($i > 0) {
            app(AttachReferrer::class)->atRegistration($account, $accounts[$i - 1], null);
        }

        $accounts[] = $account;
    }

    return $accounts;
}

/**
 * The multi-level programme switched on, as an administrator would (D24).
 */
function referralTestSwitchOn(): void
{
    app(ReferralSettings::class)->switchTo(true, User::factory()->create(), 'Switched on for a test.');
}

/**
 * An account ready for activation under `$referrer`, its verified activation
 * payment itemised as a registration fee, a package fee and an optional
 * discount, and — when a package is named — a subscription waiting for it.
 */
function referralTestNewcomer(?BusinessAccount $referrer, ?Package $package = null, int $registration = 150000, int $packageFee = 500000, int $discount = 0): BusinessAccount
{
    $account = testAccountReadyForActivation();

    if ($referrer !== null) {
        app(AttachReferrer::class)->atRegistration($account, $referrer, null);
    }

    if ($package !== null) {
        UserPackage::create([
            'business_account_id' => $account->id,
            'package_id' => $package->id,
            'status' => UserPackageStatus::PendingPayment,
            'paid_fee_minor' => $packageFee,
            'currency_code' => 'BDT',
        ]);
    }

    $payment = Payment::query()
        ->where('business_account_id', $account->id)
        ->where('purpose', PaymentPurpose::Activation)
        ->firstOrFail();

    $payment->allocations()->create(['type' => AllocationType::RegistrationFee, 'amount_minor' => $registration, 'currency_code' => 'BDT']);
    $payment->allocations()->create(['type' => AllocationType::PackageFee, 'amount_minor' => $packageFee, 'currency_code' => 'BDT']);

    if ($discount > 0) {
        $payment->allocations()->create(['type' => AllocationType::Discount, 'amount_minor' => $discount, 'currency_code' => 'BDT']);
    }

    return $account;
}

/**
 * Activate through the real approval, as an administrator does.
 */
function referralTestActivate(BusinessAccount $account): BusinessAccount
{
    return app(ActivateAccount::class)->handle($account, User::factory()->create()->id);
}
