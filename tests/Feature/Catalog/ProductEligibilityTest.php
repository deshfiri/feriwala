<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Package and account eligibility (P3-9, §11.1, §12).
 *
 * The server decides who may see a product, from the product's status, the
 * account's standing, its live subscription, and the product's two scopes.
 * `refusals()` answers for one product and `query()` for a list; both are held
 * to the same answers here, so the list a partner browses can never contain a
 * product they would be refused.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->eligibility = app(ProductEligibility::class);

    $this->basic = catalogEligibilityPackage('Basic');
    $this->premium = catalogEligibilityPackage('Premium');

    $this->category = Category::create(['name' => 'Kitchen']);
});

function catalogEligibilityPackage(string $name): Package
{
    return Package::create([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'name' => $name,
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function catalogEligibilityAccount(?Package $package, AccountStatus $status = AccountStatus::Active): BusinessAccount
{
    $account = testBusinessAccount($status);

    if ($package !== null) {
        $subscription = UserPackage::create([
            'business_account_id' => $account->id,
            'package_id' => $package->id,
            'status' => UserPackageStatus::Active,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'paid_fee' => Money::fromDecimal('5000.00', Currency::BDT),
            'currency_code' => 'BDT',
        ]);

        $account->forceFill(['current_user_package_id' => $subscription->id])->save();
    }

    return $account->refresh();
}

function catalogEligibilityProduct(string $sku, array $attributes = []): Product
{
    return Product::create([
        'name' => "Product {$sku}",
        'sku' => $sku,
        'category_id' => Category::query()->value('id'),
        'wholesale_price' => Money::fromDecimal('1000.00', Currency::BDT),
        'status' => ProductStatus::Active,
        ...$attributes,
    ]);
}

describe('who decides eligibility (§12)', function () {
    it('refuses a business account holder and staff who may only read', function () {
        $product = catalogEligibilityProduct('FW-1');
        $payload = ['package_scope' => 'all', 'account_scope' => 'any'];

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->put(route('admin.catalog.products.eligibility.update', $product->public_id), $payload)
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->put(route('admin.catalog.products.eligibility.update', $product->public_id), $payload)
            ->assertForbidden();

        expect($product->refresh()->package_scope)->toBe(PackageScope::SelectedPackages);
    });
});

describe('recording the rules', function () {
    it('starts every new product offered to no package', function () {
        expect(catalogEligibilityProduct('FW-1')->refresh()->package_scope)->toBe(PackageScope::SelectedPackages)
            ->and(Product::query()->firstOrFail()->account_scope)->toBe(AccountScope::AnyAccount);
    });

    it('saves both scopes and both lists as one decision, and replaces them on the next', function () {
        $product = catalogEligibilityProduct('FW-1');
        $account = catalogEligibilityAccount($this->basic);
        $url = route('admin.catalog.products.eligibility.update', $product->public_id);

        $this->actingAs($this->manager)->put($url, [
            'package_scope' => 'selected',
            'package_ids' => [$this->basic->public_id, $this->premium->public_id],
            'account_scope' => 'selected',
            'account_ids' => [$account->public_id],
        ])->assertSessionHasNoErrors();

        $product->refresh();

        expect($product->account_scope)->toBe(AccountScope::SelectedAccounts)
            ->and($product->eligiblePackages()->count())->toBe(2)
            ->and($product->eligibleAccounts()->pluck('business_accounts.id')->all())->toBe([$account->id]);

        $this->actingAs($this->manager)->put($url, [
            'package_scope' => 'selected',
            'package_ids' => [$this->premium->public_id],
            'account_scope' => 'any',
        ])->assertSessionHasNoErrors();

        expect($product->eligiblePackages()->pluck('packages.id')->all())->toBe([$this->premium->id])
            ->and($product->refresh()->account_scope)->toBe(AccountScope::AnyAccount);
    });

    it('refuses a package or account it cannot find, and a scope it does not know', function () {
        $product = catalogEligibilityProduct('FW-1');

        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.eligibility.update', $product->public_id), [
                'package_scope' => 'everyone',
                'package_ids' => ['not-a-package'],
                'account_scope' => 'any',
                'account_ids' => ['not-an-account'],
            ])
            ->assertSessionHasErrors(['package_scope', 'package_ids.0', 'account_ids.0']);
    });

    it('holds each pairing unique, and the scopes to known values, in the database', function () {
        $product = catalogEligibilityProduct('FW-1');
        $product->eligiblePackages()->attach($this->basic->id);

        expect(fn () => DB::table('product_package_eligibility')->insert(['product_id' => $product->id, 'package_id' => $this->basic->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses an unknown scope in the database', function () {
        $product = catalogEligibilityProduct('FW-1');

        expect(fn () => DB::table('products')->where('id', $product->id)->update(['package_scope' => 'everyone']))
            ->toThrow(QueryException::class, 'products_package_scope_known');
    });
});

describe('the server’s answer', function () {
    it('offers an active product to an account on an allowed package', function () {
        $product = catalogEligibilityProduct('FW-1');
        $product->eligiblePackages()->attach($this->basic->id);

        expect($this->eligibility->isEligible($product, catalogEligibilityAccount($this->basic)))->toBeTrue()
            ->and($this->eligibility->refusals($product, catalogEligibilityAccount($this->premium)))
            ->toBe([ProductEligibility::PACKAGE_NOT_ELIGIBLE]);
    });

    it('offers a product scoped to every package to any account with one, and to none without', function () {
        $product = catalogEligibilityProduct('FW-1', ['package_scope' => PackageScope::AllPackages]);

        expect($this->eligibility->isEligible($product, catalogEligibilityAccount($this->premium)))->toBeTrue()
            ->and($this->eligibility->refusals($product, catalogEligibilityAccount(null)))
            ->toBe([ProductEligibility::NO_ACTIVE_PACKAGE]);
    });

    it('offers nothing that is not active', function () {
        $product = catalogEligibilityProduct('FW-1', ['package_scope' => PackageScope::AllPackages, 'status' => ProductStatus::PendingReview]);

        expect($this->eligibility->refusals($product, catalogEligibilityAccount($this->basic)))
            ->toBe([ProductEligibility::NOT_ACTIVE]);
    });

    it('offers nothing to an account that cannot trade, whatever its package', function () {
        $product = catalogEligibilityProduct('FW-1', ['package_scope' => PackageScope::AllPackages]);
        $suspended = catalogEligibilityAccount($this->basic, AccountStatus::Suspended);

        expect($this->eligibility->refusals($product, $suspended))->toContain(ProductEligibility::ACCOUNT_CANNOT_TRANSACT);
    });

    it('restricts to listed accounts on top of packages, never around them', function () {
        $listedOnBasic = catalogEligibilityAccount($this->basic);
        $listedOnPremium = catalogEligibilityAccount($this->premium);
        $unlistedOnBasic = catalogEligibilityAccount($this->basic);

        $product = catalogEligibilityProduct('FW-1', ['account_scope' => AccountScope::SelectedAccounts]);
        $product->eligiblePackages()->attach($this->basic->id);
        $product->eligibleAccounts()->attach([$listedOnBasic->id, $listedOnPremium->id]);

        expect($this->eligibility->isEligible($product, $listedOnBasic))->toBeTrue()
            ->and($this->eligibility->refusals($product, $listedOnPremium))->toBe([ProductEligibility::PACKAGE_NOT_ELIGIBLE])
            ->and($this->eligibility->refusals($product, $unlistedOnBasic))->toBe([ProductEligibility::ACCOUNT_NOT_LISTED]);
    });

    it('lists exactly the products it would answer yes for', function () {
        $onBasic = catalogEligibilityAccount($this->basic);
        $onPremium = catalogEligibilityAccount($this->premium);

        $everyone = catalogEligibilityProduct('FW-ALL', ['package_scope' => PackageScope::AllPackages]);
        $basicOnly = catalogEligibilityProduct('FW-BASIC');
        $basicOnly->eligiblePackages()->attach($this->basic->id);
        $exclusive = catalogEligibilityProduct('FW-EXCL', ['account_scope' => AccountScope::SelectedAccounts]);
        $exclusive->eligiblePackages()->attach([$this->basic->id, $this->premium->id]);
        $exclusive->eligibleAccounts()->attach($onPremium->id);
        catalogEligibilityProduct('FW-DRAFT', ['package_scope' => PackageScope::AllPackages, 'status' => ProductStatus::Draft]);

        foreach ([$onBasic, $onPremium] as $account) {
            $listed = $this->eligibility->query($account)->orderBy('sku')->pluck('sku')->all();
            $answered = Product::query()->orderBy('sku')->get()
                ->filter(fn (Product $product) => $this->eligibility->isEligible($product, $account))
                ->pluck('sku')->values()->all();

            expect($listed)->toBe($answered);
        }

        expect($this->eligibility->query($onBasic)->orderBy('sku')->pluck('sku')->all())->toBe(['FW-ALL', 'FW-BASIC'])
            ->and($this->eligibility->query($onPremium)->orderBy('sku')->pluck('sku')->all())->toBe(['FW-ALL', 'FW-EXCL'])
            ->and($this->eligibility->query(catalogEligibilityAccount(null))->count())->toBe(0);
    });
});

describe('the editor', function () {
    it('shows the rules and the packages to choose from', function () {
        $product = catalogEligibilityProduct('FW-1');
        $product->eligiblePackages()->attach($this->basic->id);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('eligibility.package_scope', 'selected')
                ->where('eligibility.package_ids', [$this->basic->public_id])
                ->has('package_options', 2)
                ->missing('account_matches'),
            );
    });

    it('finds accounts by name only when asked, by partial reload', function () {
        $product = catalogEligibilityProduct('FW-1');
        $account = catalogEligibilityAccount($this->basic);
        $account->forceFill(['name' => 'Dhaka Traders'])->save();

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', [$product->public_id, 'account_search' => 'dhaka']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('account_matches')
                ->reloadOnly('account_matches', fn (Assert $reload) => $reload
                    ->where('account_matches.0.name', 'Dhaka Traders')
                    ->has('account_matches', 1),
                ),
            );
    });

    it('removes a product together with its eligibility, on permanent delete', function () {
        $product = catalogEligibilityProduct('FW-1', ['status' => ProductStatus::Draft]);
        $product->eligiblePackages()->attach($this->basic->id);

        app(ManageProducts::class)->trash($this->manager, $product, 'Test.');
        app(ManageProducts::class)->permanentlyDelete($this->manager, $product->refresh());

        expect(Product::withTrashed()->count())->toBe(0)
            ->and(DB::table('product_package_eligibility')->count())->toBe(0);
    });
});
