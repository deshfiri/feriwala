<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentPurpose;
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
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Models\CartItem;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Account Type (Conditional / Non-Conditional) at wholesale checkout (D-new).
 *
 * A Non-Conditional account pays only the delivery charge upfront; the product
 * cost is recovered later from the COD amount collected at delivery. A
 * Non-Conditional line must declare a resale/COD amount, bounded by the
 * product's own selling-price guidance, frozen into the order's immutable
 * snapshot at placement. Conditional orders are charged exactly as before.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->package = ncoPackage();
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->category = Category::create(['name' => 'Kitchen']);

    // Product cost 10,000 taka, guidance floor 11,000, ceiling 13,000 — the
    // worked example the Project Owner gave, scaled to one unit for clarity.
    $this->kettle = ncoProduct('FW-KT', 'Electric kettle', '10000.00', [
        'minimum_selling_price' => Money::fromDecimal('11000.00', Currency::BDT),
        'maximum_selling_price' => Money::fromDecimal('13000.00', Currency::BDT),
        'suggested_selling_price' => Money::fromDecimal('12000.00', Currency::BDT),
    ]);
    ncoStock($this->kettle, 50);
});

function ncoPackage(): Package
{
    return Package::create([
        'slug' => 'nco-'.Str::lower(Str::random(8)),
        'name' => 'Non-Conditional package',
        'currency_code' => 'BDT',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'is_active' => true,
        'is_public' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ncoAccount(Package $package, array $overrides = []): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'currency_code' => 'BDT',
        'paid_fee' => Money::fromDecimal('5000.00', Currency::BDT),
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id, ...$overrides])->save();

    return $account->refresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ncoProduct(string $sku, string $name, string $price, array $overrides = []): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => test()->category->id,
        'currency_code' => 'BDT',
        'base_cost' => Money::fromDecimal('500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal($price, Currency::BDT),
        'min_order_quantity' => 1,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
        ...$overrides,
    ]);
}

function ncoStock(Product $product, int $units): StockItem
{
    $item = StockItem::create(['warehouse_id' => test()->warehouse->id, 'product_id' => $product->id]);
    app(StockLedger::class)->move($item, null, StockBucket::Available, $units, StockMovementType::Adjustment);

    return $item;
}

/**
 * @param  array<string, mixed>  $payload
 */
function ncoAddToCart(BusinessAccount $account, array $payload): TestResponse
{
    return test()->actingAs($account->owner)->post(route('wholesale.cart.items.store'), $payload);
}

function ncoAddressAndConfirm(BusinessAccount $account): void
{
    $address = [
        'contact_name' => 'Karim Uddin',
        'contact_mobile' => '01712345678',
        'line_1' => 'House 12, Road 5',
        'area' => 'Mirpur',
        'city' => 'Dhaka',
        'postcode' => '1216',
    ];

    app(SaveCheckoutAddress::class)->handle($account->owner, AddressType::Billing, $address);
    app(SaveCheckoutAddress::class)->handle($account->owner, AddressType::Shipping, $address);

    app(ConfirmCheckout::class)->handle($account->owner, $account, 'sslcommerz', ncoFingerprint($account));
}

function ncoFingerprint(BusinessAccount $account): string
{
    return app(PriceCheckout::class)->quote(app(OpenCart::class)->find($account->owner, $account), $account)->fingerprint();
}

function ncoPlaceOrder(BusinessAccount $account): TestResponse
{
    return test()->actingAs($account->owner)->post(route('wholesale.orders.store'), [
        'fingerprint' => ncoFingerprint($account),
    ]);
}

describe('adding a Non-Conditional line to the cart', function () {
    it('requires a resale amount, within the product\'s own selling-price guidance', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])
            ->assertSessionHasErrors(['resale_amount' => __('wholesale.refused.resale_amount_required_line')]);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '10500.00'])
            ->assertSessionHasErrors(['resale_amount' => __('wholesale.refused.resale_amount_below_minimum', ['minimum' => '11000.00'])]);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '13500.00'])
            ->assertSessionHasErrors(['resale_amount' => __('wholesale.refused.resale_amount_above_maximum', ['maximum' => '13000.00'])]);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '12000.00'])
            ->assertSessionHasNoErrors();

        $line = CartItem::query()->where('product_id', $this->kettle->id)->sole();

        expect($line->resale_amount->toDecimal())->toBe('12000.00');
    });

    it('falls back to the product\'s own unit price as the floor when no guidance is configured', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        $plain = ncoProduct('FW-PLAIN', 'Unguided product', '3000.00');
        ncoStock($plain, 10);

        ncoAddToCart($account, ['product' => $plain->slug, 'quantity' => 1, 'resale_amount' => '2999.99'])
            ->assertSessionHasErrors(['resale_amount' => __('wholesale.refused.resale_amount_below_minimum', ['minimum' => '3000.00'])]);

        ncoAddToCart($account, ['product' => $plain->slug, 'quantity' => 1, 'resale_amount' => '3000.00'])
            ->assertSessionHasNoErrors();
    });

    it('stores no resale amount for a Conditional account, even when one is sent', function () {
        $account = ncoAccount($this->package);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '12000.00'])
            ->assertSessionHasNoErrors();

        expect(CartItem::query()->where('product_id', $this->kettle->id)->sole()->resale_amount)->toBeNull();
    });
});

describe('placing the order', function () {
    it('charges a Non-Conditional account only the delivery charge, deferring the product cost', function () {
        FeeRule::create([
            'fee_type' => FeeType::WholesaleDelivery,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('120.00', Currency::BDT),
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2, 'resale_amount' => '12000.00'])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        expect($order->account_type)->toBe(AccountType::NonConditional)
            // The order's own value is still the full 20,120 — only the
            // payment actually collected is delivery-only.
            ->and($order->total->toDecimal())->toBe('20120.00')
            ->and($order->payment->purpose)->toBe(PaymentPurpose::WholesaleOrder)
            ->and($order->payment->amount->toDecimal())->toBe('120.00')
            ->and($order->payment->allocations->pluck('type')->all())->toBe([AllocationType::DeliveryCharge]);

        $line = $order->items()->sole();

        expect($line->resale_amount->toDecimal())->toBe('12000.00')
            ->and($line->line_total->toDecimal())->toBe('20000.00');
    });

    it('charges a Conditional account the full product and delivery cost, exactly as before', function () {
        $account = ncoAccount($this->package);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        expect($order->account_type)->toBe(AccountType::Conditional)
            ->and($order->payment->amount->toDecimal())->toBe('20000.00')
            ->and($order->payment->allocations->pluck('type')->all())->toBe([AllocationType::WholesaleGoods])
            ->and($order->items()->sole()->resale_amount)->toBeNull();
    });

    it('refuses to place a Non-Conditional order missing a resale amount, rather than guessing one', function () {
        $account = ncoAccount($this->package);
        // Added while still Conditional, so the line carries no resale amount.
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        // Flips to Non-Conditional after the cart line and the confirmation
        // already exist.
        $account->forceFill(['account_type' => AccountType::NonConditional])->save();

        ncoPlaceOrder($account)->assertSessionHasErrors(['cart' => __('wholesale.refused.resale_amount_required')]);

        expect(Order::query()->where('business_account_id', $account->id)->count())->toBe(0);
    });

    it('freezes the order\'s account_type snapshot: a later change to the account never rewrites a placed order', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '12000.00'])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);
        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        $account->forceFill(['account_type' => AccountType::Conditional])->save();

        expect($order->fresh()->account_type)->toBe(AccountType::NonConditional)
            ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['account_type' => 'conditional']))
            ->toThrow(QueryException::class);
    });

    it('keeps order_items.resale_amount immutable, like every other line snapshot', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '12000.00'])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);
        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $line = Order::query()->where('business_account_id', $account->id)->sole()->items()->sole();

        expect(fn () => DB::table('order_items')->where('id', $line->id)->update(['resale_amount' => '1.00']))
            ->toThrow(QueryException::class);
    });
});
