<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Enums\SupplierAddressType;
use App\Domain\Address\Models\SharedAddress;
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
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
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
use App\Domain\Supplier\Actions\SetSupplierOfferRates;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferPriceChange;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Queries\ResolvePreferredOffer;
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
use Illuminate\Support\Facades\Auth;
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
pest()->group('supplier')->in('Feature/Supplier');
pest()->group('database')->in('Feature/Database');
pest()->group('location')->in('Feature/Location');
pest()->group('address')->in('Feature/Address');
pest()->group('bank')->in('Feature/Bank');

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
        'amount' => Money::fromDecimal('6000.00'),
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
        'fee' => Money::fromDecimal('5000.00'),
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
        'paid_fee' => Money::fromDecimal('5000.00'),
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
 *
 * `$minorUnits` keeps its old poisha-shorthand name so call sites do not all
 * need to change, but it is converted to exact Taka once, here, via bcmath —
 * never scaled at the column or the cast (D26).
 */
function websiteTestFee(FeeType $type, int $minorUnits): FeeRule
{
    return FeeRule::create([
        'fee_type' => $type->value,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal(bcdiv((string) $minorUnits, '100', 2)),
        'effective_from' => now()->subDay(),
    ]);
}

/**
 * An open wallet holding `$credit` Taka for the account.
 */
function websiteTestWallet(BusinessAccount $account, string $credit): Wallet
{
    $wallet = app(OpenWallet::class)->handle($account);
    $amount = Money::fromDecimal($credit, Currency::BDT);

    if ($amount->isPositive()) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            $amount,
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
        'base_cost' => Money::zero(Currency::BDT),
        'wholesale_price' => Money::fromDecimal('1500.00', Currency::BDT),
        'minimum_selling_price' => Money::fromDecimal('2000.00', Currency::BDT),
        'maximum_selling_price' => Money::fromDecimal('4000.00', Currency::BDT),
        'suggested_selling_price' => Money::fromDecimal('2500.00', Currency::BDT),
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
 * @param  array{timestamp?: string, nonce?: string, secret?: string, key_id?: string, authorization?: string|null, https?: bool, headers?: array<string, string>}  $overrides
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

    // Anything the call needs besides the signature: an idempotency key, say.
    $headers = [...$headers, ...($overrides['headers'] ?? [])];

    $scheme = ($overrides['https'] ?? true) ? 'https' : 'http';
    $url = $scheme.'://localhost'.$fullPath.($rawQuery === '' ? '' : '?'.$rawQuery);

    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    // The body's type is not an HTTP_ server variable: Symfony reads CONTENT_TYPE.
    if (isset($headers['Content-Type'])) {
        $server['CONTENT_TYPE'] = $headers['Content-Type'];
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
        'fee' => Money::fromDecimal('5000.00'),
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
        'paid_fee' => Money::fromDecimal('5000.00'),
        'currency_code' => 'BDT',
    ]);

    $account->forceFill(['current_user_package_id' => $userPackage->id])->save();

    return $account->refresh();
}

/**
 * A multi-level plan version in force from an hour ago (D24).
 *
 * Each level is `[type, value]` or `[type, value, cap]`: a fixed amount as a
 * flat-Taka decimal string (D26; `'100.00'` is BDT 100.00), or a percentage
 * as text (`'10'`, `'2.5'`). Anything else a test needs to vary goes in
 * `$overrides`, keyed as the draft names it.
 *
 * @param  list<array{0: string, 1: int|string, 2?: int|null}>  $levels
 * @param  array<string, mixed>  $overrides
 */
function referralTestPlan(array $levels, array $overrides = []): ReferralPlan
{
    $rule = fn (array $level) => $level[0] === 'fixed'
        ? new RewardRule(RewardType::Fixed, amount: Money::fromDecimal((string) $level[1]), cap: isset($level[2]) ? Money::fromDecimal((string) $level[2]) : null)
        : new RewardRule(RewardType::Percentage, rateBps: RewardRule::basisPointsFromPercent((string) $level[1]), cap: isset($level[2]) ? Money::fromDecimal((string) $level[2]) : null);

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
        'minimumQualifyingPayment' => Money::zero(),
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
function referralTestNewcomer(?BusinessAccount $referrer, ?Package $package = null, string $registration = '1500.00', string $packageFee = '5000.00', string $discount = '0.00'): BusinessAccount
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
            'paid_fee' => Money::fromDecimal($packageFee),
            'currency_code' => 'BDT',
        ]);
    }

    $payment = Payment::query()
        ->where('business_account_id', $account->id)
        ->where('purpose', PaymentPurpose::Activation)
        ->firstOrFail();

    $payment->allocations()->create(['type' => AllocationType::RegistrationFee, 'amount' => Money::fromDecimal($registration), 'currency_code' => 'BDT']);
    $payment->allocations()->create(['type' => AllocationType::PackageFee, 'amount' => Money::fromDecimal($packageFee), 'currency_code' => 'BDT']);

    if (Money::fromDecimal($discount)->isPositive()) {
        $payment->allocations()->create(['type' => AllocationType::Discount, 'amount' => Money::fromDecimal($discount), 'currency_code' => 'BDT']);
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

/*
 * Shared fixtures for the Supplier account domain (D25). Prefixed
 * `supplierTest` so they cannot collide with anything else in Pest's single
 * global function namespace.
 */

/**
 * Signs a Supplier in on its own guard **without** `actingAs()`, which calls
 * `Auth::shouldUse()` and would make `supplier` the default guard for the
 * rest of the test — defeating the isolation these tests exist to prove.
 */
function supplierTestSignIn(Supplier $supplier): Supplier
{
    Auth::guard('supplier')->login($supplier);

    return $supplier;
}

/**
 * A listing request awaiting staff review, with `$items` proposed variations.
 *
 * @param  list<array<string, mixed>>  $items
 */
function supplierTestListing(Supplier $supplier, array $items = [[]], ListingStatus $status = ListingStatus::UnderReview): SupplierProductListing
{
    $listing = $supplier->listings()->create([
        'product_name' => 'Cotton panjabi',
        'description' => 'A supplier proposal.',
        'status' => $status,
        'submitted_at' => now(),
    ]);

    foreach ($items as $index => $item) {
        $listing->items()->create([
            'variant_label' => count($items) > 1 ? 'Size '.($index + 1) : null,
            'supplier_sku' => 'SUP-'.Str::upper(Str::random(6)),
            'supplier_rate' => Money::fromDecimal('1000.00', Currency::BDT),
            'currency_code' => 'BDT',
            'available_quantity' => 50,
            'minimum_supply_quantity' => 1,
            ...$item,
        ]);
    }

    return $listing->refresh();
}

/**
 * An active offer priced at 1,000 taka from the Supplier and 1,300 from the
 * platform, on a fresh Central Product (or the one given).
 *
 * Writes no price-history row — several existing tests assert an exact
 * history count from zero. A test that allocates an order line to this offer
 * needs one ({@see ResolvePreferredOffer} reads history, not the offer's own
 * denormalised figures) and adds it itself; see
 * {@see supplierTestOfferPriceVersion()}.
 */
function supplierTestOffer(
    ?Supplier $supplier = null,
    ?Product $product = null,
    string $supplierRate = '1000.00',
    string $platformRate = '1300.00',
    bool $preferred = false,
): SupplierOffer {
    $supplier ??= Supplier::factory()->create();
    $product ??= websiteTestProduct();

    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'status' => OfferStatus::Active,
        'is_preferred' => $preferred,
        'supplier_rate' => Money::fromDecimal($supplierRate, Currency::BDT),
        'platform_rate' => Money::fromDecimal($platformRate, Currency::BDT),
        'currency_code' => 'BDT',
        'wholesale_enabled' => true,
        'activated_at' => now(),
    ]);

    $offer->stock()->create(['quantity' => 10]);

    return $offer;
}

/*
 * Shared fixtures for the Location Directory + Shared Address module.
 * Prefixed `addressTest`/`locationTest` so they cannot collide with anything
 * else in Pest's single global function namespace — see
 * tests/Feature/Location/ImportBdLocationsTest.php for `locationTestFixture()`,
 * a different (raw-JSON) fixture for the importer itself.
 */

/**
 * A valid, saved four-level `BdLocation` chain, independent of every other
 * test's — each call mints its own random source ids, so tests may build as
 * many chains as they need without colliding.
 *
 * @return array{division: BdLocation, district: BdLocation, upazila: BdLocation, union: BdLocation}
 */
function addressTestLocationChain(): array
{
    $division = BdLocation::create([
        'type' => BdLocationType::Division,
        'source_id' => Str::random(8),
        'source_parent_id' => null,
        'name_en' => 'Test Division',
        'name_bn' => 'টেস্ট বিভাগ',
        'is_active' => true,
    ]);

    $district = BdLocation::create([
        'type' => BdLocationType::District,
        'parent_id' => $division->id,
        'source_id' => Str::random(8),
        'source_parent_id' => $division->source_id,
        'name_en' => 'Test District',
        'name_bn' => 'টেস্ট জেলা',
        'is_active' => true,
    ]);

    $upazila = BdLocation::create([
        'type' => BdLocationType::Upazila,
        'parent_id' => $district->id,
        'source_id' => Str::random(8),
        'source_parent_id' => $district->source_id,
        'name_en' => 'Test Upazila',
        'name_bn' => 'টেস্ট উপজেলা',
        'is_active' => true,
    ]);

    $union = BdLocation::create([
        'type' => BdLocationType::Union,
        'parent_id' => $upazila->id,
        'source_id' => Str::random(8),
        'source_parent_id' => $upazila->source_id,
        'name_en' => 'Test Union',
        'name_bn' => 'টেস্ট ইউনিয়ন',
        'is_active' => true,
    ]);

    return compact('division', 'district', 'upazila', 'union');
}

/**
 * A saved `SharedAddress` for `$ownerType`/`$ownerId`, through the real
 * {@see SaveSharedAddress} action (not `SharedAddress::create()`), so its
 * `location_snapshot` is resolved exactly as a real request would build it.
 *
 * @param  array<string, mixed>  $overrides  'type', 'contactName', 'contactMobile', 'makeDefault'
 */
function addressTestCreate(AddressOwnerType $ownerType, int $ownerId, array $overrides = []): SharedAddress
{
    $chain = addressTestLocationChain();

    $defaultType = $ownerType === AddressOwnerType::BusinessAccount
        ? ClientAddressType::Business->value
        : SupplierAddressType::Registered->value;

    return app(SaveSharedAddress::class)->handle(
        ownerType: $ownerType,
        ownerId: $ownerId,
        type: $overrides['type'] ?? $defaultType,
        contactName: $overrides['contactName'] ?? 'Test Contact',
        contactMobile: $overrides['contactMobile'] ?? '+8801700000000',
        divisionId: $chain['division']->id,
        districtId: $chain['district']->id,
        upazilaId: $chain['upazila']->id,
        unionId: $chain['union']->id,
        detailedAddress: 'House 1, Road 2',
        landmark: null,
        postcode: null,
        makeDefault: (bool) ($overrides['makeDefault'] ?? false),
    );
}

/**
 * The price-change row {@see SetSupplierOfferRates} always leaves behind,
 * backdated so it is already effective — for a test that allocates an order
 * line to a {@see supplierTestOffer()} fixture and needs
 * {@see ResolvePreferredOffer} to find a price version for it (D25, P13-21).
 */
function supplierTestOfferPriceVersion(SupplierOffer $offer): SupplierOfferPriceChange
{
    return $offer->priceHistory()->create([
        'supplier_rate' => $offer->supplier_rate,
        'platform_rate' => $offer->platform_rate,
        'currency_code' => $offer->currency_code,
        'effective_from' => now()->subMinute(),
        'reason' => 'Fixture rate.',
        'created_at' => now(),
    ]);
}
