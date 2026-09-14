<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Tax\Data\TaxCharge;
use App\Domain\Tax\Models\TaxExemption;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Data\CheckoutQuote;
use App\Domain\Wholesale\Queries\PriceCheckout;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Addresses, delivery charge and tax at ERP wholesale checkout (P4-7, §14, D19).
 *
 * Delivery is priced by fee rule for the account's package, tax by the one tax
 * engine — per line on what the line sells for after its share of the discount,
 * and on delivery — and none of it is taken from the browser. Addresses are the
 * person's own, kept one of each kind, and never reached by identifier.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->package = wholesaleChargesPackage();
    $this->otherPackage = wholesaleChargesPackage();
    $this->karim = wholesaleChargesAccount($this->package);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->kettles = Category::create(['name' => 'Kettles', 'parent_id' => $this->kitchen->id]);
    $apparel = Category::create(['name' => 'Apparel']);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    // Ten kettles at 2,000 taka and ten shirts at 1,000: 30,000 taka of goods.
    $this->kettle = wholesaleChargesProduct('FW-KT', 'Electric kettle', $this->kettles, 200000);
    $this->shirt = wholesaleChargesProduct('FW-SH', 'Polo shirt', $apparel, 100000);

    $this->actingAs($this->karim->owner);

    foreach ([$this->kettle, $this->shirt] as $product) {
        $this->post(route('wholesale.cart.items.store'), ['product' => $product->slug, 'quantity' => 10])
            ->assertSessionHasNoErrors();
    }
});

function wholesaleChargesPackage(): Package
{
    return Package::create([
        'slug' => 'charges-'.Str::lower(Str::random(8)),
        'name' => 'Charges package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleChargesAccount(Package $package): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'paid_fee_minor' => 500000,
        'currency_code' => 'BDT',
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return $account->refresh();
}

function wholesaleChargesProduct(string $sku, string $name, Category $category, int $priceMinor): Product
{
    $product = Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => $category->id,
        'base_cost_minor' => 50000,
        'wholesale_price_minor' => $priceMinor,
        'min_order_quantity' => 1,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);

    $stock = StockItem::create(['warehouse_id' => test()->warehouse->id, 'product_id' => $product->id]);
    app(StockLedger::class)->move($stock, null, StockBucket::Available, 50, StockMovementType::Adjustment);

    return $product;
}

function wholesaleChargesDelivery(int $amountMinor, ?Package $package = null): FeeRule
{
    return FeeRule::create([
        'fee_type' => FeeType::WholesaleDelivery,
        'package_id' => $package?->id,
        'amount_minor' => $amountMinor,
        'currency_code' => 'BDT',
        'effective_from' => now()->subDay(),
        'is_active' => true,
    ]);
}

function wholesaleChargesQuote(): CheckoutQuote
{
    $account = test()->karim;

    return app(PriceCheckout::class)->quote(app(OpenCart::class)->find($account->owner, $account), $account);
}

/**
 * The tax charged at each rate, keyed by code.
 *
 * @return array<string, array{net: int, tax: int, mode: string}>
 */
function wholesaleChargesTaxByCode(CheckoutQuote $quote): array
{
    return collect($quote->tax->charges)
        ->mapWithKeys(fn (TaxCharge $charge) => [$charge->code => [
            'net' => $charge->net->minorUnits,
            'tax' => $charge->tax->minorUnits,
            'mode' => $charge->mode->value,
        ]])
        ->all();
}

function wholesaleChargesApplyCoupon(int $fixedMinor): void
{
    $coupon = Coupon::create([
        'code' => 'BULK'.Str::upper(Str::random(6)),
        'name' => 'Bulk buyer',
        'discount_type' => DiscountType::Fixed,
        'value' => $fixedMinor,
        'currency_code' => 'BDT',
        'applies_to' => CouponScope::WholesaleOrder,
        'per_account_limit' => 1,
        'effective_from' => now()->subDay(),
        'is_active' => true,
    ]);

    test()->put(route('wholesale.checkout.coupon.apply'), ['code' => $coupon->code])->assertSessionHasNoErrors();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wholesaleChargesAddress(array $overrides = []): array
{
    return [
        'contact_name' => 'Karim Uddin',
        'contact_mobile' => '01712345678',
        'line_1' => 'House 12, Road 5',
        'line_2' => 'Block C',
        'area' => 'Mirpur',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'postcode' => '1216',
        ...$overrides,
    ];
}

describe('delivery charge', function () {
    it('charges nothing for delivery when no rule is configured', function () {
        $quote = wholesaleChargesQuote();

        expect($quote->delivery->minorUnits)->toBe(0)
            ->and($quote->total->minorUnits)->toBe(3000000);
    });

    it('charges the global delivery rule, and a rule for the account\'s own package ahead of it', function () {
        wholesaleChargesDelivery(12000);
        wholesaleChargesDelivery(1000, $this->otherPackage);

        expect(wholesaleChargesQuote()->delivery->minorUnits)->toBe(12000);

        wholesaleChargesDelivery(8000, $this->package);

        $quote = wholesaleChargesQuote();

        expect($quote->delivery->minorUnits)->toBe(8000)
            ->and($quote->total->minorUnits)->toBe(3008000);
    });

    it('charges no delivery on a cart with nothing to buy', function () {
        wholesaleChargesDelivery(12000);

        expect(app(PriceCheckout::class)->quote(null, $this->karim)->delivery->minorUnits)->toBe(0);
    });
});

describe('tax', function () {
    it('charges no tax when nothing is configured', function () {
        $quote = wholesaleChargesQuote();

        expect($quote->tax->isEmpty())->toBeTrue()
            ->and($quote->total->minorUnits)->toBe(3000000);
    });

    it('taxes goods after the discount and the delivery charge, and adds it to the total', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->create();
        wholesaleChargesDelivery(10000);
        wholesaleChargesApplyCoupon(300000);

        // Goods 30,000 − 3,000 discount = 27,000 at 15% is 4,050; delivery 100 at 15% is 15.
        $this->get(route('wholesale.checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wholesale/checkout')
                ->where('checkout.subtotal.minor_units', 3000000)
                ->where('checkout.discount.minor_units', 300000)
                ->where('checkout.delivery.minor_units', 10000)
                ->has('checkout.tax', 1)
                ->where('checkout.tax.0.code', 'vat-standard')
                ->where('checkout.tax.0.net.minor_units', 2710000)
                ->where('checkout.tax.0.tax.minor_units', 406500)
                ->where('checkout.tax_added.minor_units', 406500)
                ->where('checkout.total.minor_units', 3116500));
    });

    it('resolves tax on goods by product, then category, then the category above it, then everything', function () {
        TaxRate::factory()->create();
        TaxRate::factory()->code('vat-reduced')->percent(5)->create();
        TaxRate::factory()->code('vat-kettles')->percent(10)->create();
        TaxRate::factory()->zeroRated()->create();
        TaxRule::factory()->create();

        // A rule on the parent category reaches a product in its subcategory.
        TaxRule::factory()->forCategory($this->kitchen->slug)->usingCode('vat-reduced')->create();

        expect(wholesaleChargesTaxByCode(wholesaleChargesQuote()))
            ->toMatchArray([
                'vat-reduced' => ['net' => 2000000, 'tax' => 100000, 'mode' => 'exclusive'],
                'vat-standard' => ['net' => 1000000, 'tax' => 150000, 'mode' => 'exclusive'],
            ]);

        // The product's own category wins over the one above it.
        TaxRule::factory()->forCategory($this->kettles->slug)->usingCode('vat-kettles')->create();

        expect(wholesaleChargesTaxByCode(wholesaleChargesQuote()))
            ->toHaveKey('vat-kettles', ['net' => 2000000, 'tax' => 200000, 'mode' => 'exclusive'])
            ->not->toHaveKey('vat-reduced');

        // And the product itself wins over both, matched whatever the case.
        TaxRule::factory()->forProduct('fw-kt')->usingCode('vat-zero')->create();

        expect(wholesaleChargesTaxByCode(wholesaleChargesQuote()))
            ->toBe(['vat-standard' => ['net' => 1000000, 'tax' => 150000, 'mode' => 'exclusive']]);
    });

    it('shares the discount across lines in proportion, without losing a poisha', function () {
        TaxRate::factory()->create();
        TaxRate::factory()->code('vat-reduced')->percent(5)->create();
        TaxRule::factory()->create();
        TaxRule::factory()->forCategory($this->kitchen->slug)->usingCode('vat-reduced')->create();

        // 1,000.01 taka off 20,000 of kettles and 10,000 of shirts: 666.68 and 333.33.
        wholesaleChargesApplyCoupon(100001);

        $quote = wholesaleChargesQuote();

        expect(wholesaleChargesTaxByCode($quote))->toMatchArray([
            'vat-reduced' => ['net' => 1933332, 'tax' => 96667, 'mode' => 'exclusive'],
            'vat-standard' => ['net' => 966667, 'tax' => 145000, 'mode' => 'exclusive'],
        ])
            ->and($quote->tax->taxableTotal()->minorUnits)->toBe(3000000 - 100001)
            ->and($quote->total->minorUnits)->toBe(3000000 - 100001 + 96667 + 145000);
    });

    it('charges no tax to an exempt account', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->create();
        wholesaleChargesDelivery(10000);

        TaxExemption::create([
            'business_account_id' => $this->karim->id,
            'reason' => 'Export processing zone',
            'effective_from' => now()->subDay(),
        ]);

        $quote = wholesaleChargesQuote();

        expect($quote->tax->isEmpty())->toBeTrue()
            ->and($quote->total->minorUnits)->toBe(3010000);
    });

    it('shows tax already inside the price without adding it again', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->inclusive()->create();

        $quote = wholesaleChargesQuote();

        // 20,000 ÷ 1.15 leaves 2,608.70 of tax; 10,000 ÷ 1.15 leaves 1,304.35.
        expect(wholesaleChargesTaxByCode($quote))
            ->toBe(['vat-standard' => ['net' => 2608695, 'tax' => 391305, 'mode' => 'inclusive']])
            ->and($quote->tax->addedTotal()->minorUnits)->toBe(0)
            ->and($quote->total->minorUnits)->toBe(3000000);
    });
});

describe('addresses', function () {
    it('keeps the billing and shipping address, and is ready to confirm only with both', function () {
        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.addresses.billing', null)
                ->where('checkout.addresses.shipping', null)
                ->where('checkout.ready_to_confirm', false));

        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.addresses.billing.city', 'Dhaka')
                ->where('checkout.addresses.billing.contact_mobile', '01712345678')
                ->where('checkout.addresses.shipping', null)
                ->where('checkout.ready_to_confirm', false));

        $this->put(route('wholesale.checkout.addresses.update', 'shipping'), wholesaleChargesAddress([
            'contact_name' => 'Warehouse desk',
            'line_1' => 'Plot 7, BSCIC',
            'line_2' => '',
            'city' => 'Gazipur',
        ]))->assertSessionHasNoErrors();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.addresses.shipping.city', 'Gazipur')
                ->where('checkout.addresses.shipping.line_2', null)
                ->where('checkout.addresses.shipping.country', 'BD')
                ->where('checkout.ready_to_confirm', true));
    });

    it('delivers to the billing address when asked', function () {
        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress())->assertSessionHasNoErrors();
        $this->put(route('wholesale.checkout.addresses.update', 'shipping'), ['same_as_billing' => '1'])->assertSessionHasNoErrors();

        $shipping = UserAddress::query()->currentFor($this->karim->owner->id, AddressType::Shipping)->sole();
        $billing = UserAddress::query()->currentFor($this->karim->owner->id, AddressType::Billing)->sole();

        expect($shipping->toSnapshot())->toBe($billing->toSnapshot());
    });

    it('will not copy a billing address that does not exist', function () {
        $this->put(route('wholesale.checkout.addresses.update', 'shipping'), ['same_as_billing' => '1'])
            ->assertSessionHasErrorsIn('shipping', ['same_as_billing' => __('wholesale.checkout.address.billing_first')]);

        expect(UserAddress::query()->count())->toBe(0);
    });

    it('validates each address in its own error bag and saves nothing it refuses', function (array $overrides, string $field) {
        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress($overrides))
            ->assertSessionHasErrorsIn('billing', [$field]);

        expect(UserAddress::query()->count())->toBe(0);
    })->with([
        'no contact name' => [['contact_name' => ''], 'contact_name'],
        'no mobile number' => [['contact_mobile' => ''], 'contact_mobile'],
        'a mobile number in words' => [['contact_mobile' => 'call me'], 'contact_mobile'],
        'a mobile number too long' => [['contact_mobile' => str_repeat('1', 21)], 'contact_mobile'],
        'no address' => [['line_1' => ''], 'line_1'],
        'no city' => [['city' => ''], 'city'],
        'a postcode too long' => [['postcode' => str_repeat('1', 17)], 'postcode'],
    ]);

    it('edits the address in place rather than keeping copies', function () {
        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress())->assertSessionHasNoErrors();
        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress(['city' => 'Chattogram']))->assertSessionHasNoErrors();

        expect(UserAddress::query()->where('type', AddressType::Billing->value)->pluck('city')->all())->toBe(['Chattogram']);
    });

    it('keeps only what the address form asks for, and prices nothing from it', function () {
        $rahim = wholesaleChargesAccount($this->package);

        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress([
            'user_id' => $rahim->owner->id,
            'type' => 'permanent',
            'country' => 'US',
            'is_default' => false,
            'delivery' => 0,
            'tax' => 0,
            'total' => 1,
        ]))->assertSessionHasNoErrors();

        $address = UserAddress::query()->sole();

        expect($address->user_id)->toBe($this->karim->owner->id)
            ->and($address->type)->toBe(AddressType::Billing)
            ->and($address->country)->toBe('BD')
            ->and($address->is_default)->toBeTrue();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page->where('checkout.total.minor_units', 3000000));
    });

    it('never shows or changes another person\'s address', function () {
        $rahim = wholesaleChargesAccount($this->package);

        $this->actingAs($rahim->owner)
            ->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress(['city' => 'Sylhet']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->karim->owner)
            ->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page->where('checkout.addresses.billing', null));

        $this->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress())->assertSessionHasNoErrors();

        expect(UserAddress::query()->where('user_id', $rahim->owner->id)->sole()->city)->toBe('Sylhet');
    });

    it('keeps billing and shipping addresses only', function () {
        $this->put('/wholesale/checkout/addresses/permanent', wholesaleChargesAddress())->assertNotFound();

        expect(UserAddress::query()->count())->toBe(0);
    });

    it('refuses an account whose package does not include wholesale purchasing', function () {
        $this->otherPackage->features()->create(['feature' => PackageFeature::WholesaleEnabled->value, 'value' => '0']);
        $closed = wholesaleChargesAccount($this->otherPackage);

        $this->actingAs($closed->owner)
            ->put(route('wholesale.checkout.addresses.update', 'billing'), wholesaleChargesAddress())
            ->assertForbidden();

        expect(UserAddress::query()->count())->toBe(0);
    });
});
