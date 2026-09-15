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
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Payment method, order summary and checkout confirmation (P4-8, §14, §26.3).
 *
 * A person confirms the summary they were shown with a gateway that can take the
 * payment. The server prices the checkout again under a lock and records the
 * confirmation only when that summary is still the one priced now; it holds only
 * while that stays true. No payment, order or reservation is made here, and the
 * wallet is not a way to pay for goods.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->package = wholesaleConfirmPackage();
    $this->karim = wholesaleConfirmAccount($this->package);

    $category = Category::create(['name' => 'Kitchen']);
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    // Ten kettles at 2,000 taka: 20,000 taka.
    $this->kettle = Product::create([
        'name' => 'Electric kettle',
        'sku' => 'FW-KT',
        'category_id' => $category->id,
        'base_cost_minor' => 50000,
        'wholesale_price_minor' => 200000,
        'min_order_quantity' => 1,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);

    $this->stock = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->kettle->id]);
    app(StockLedger::class)->move($this->stock, null, StockBucket::Available, 50, StockMovementType::Adjustment);

    $this->actingAs($this->karim->owner)
        ->post(route('wholesale.cart.items.store'), ['product' => $this->kettle->slug, 'quantity' => 10])
        ->assertSessionHasNoErrors();

    app(SaveCheckoutAddress::class)->handle($this->karim->owner, AddressType::Billing, wholesaleConfirmAddress());
    app(SaveCheckoutAddress::class)->handle($this->karim->owner, AddressType::Shipping, wholesaleConfirmAddress());
});

function wholesaleConfirmPackage(): Package
{
    return Package::create([
        'slug' => 'confirm-'.Str::lower(Str::random(8)),
        'name' => 'Confirm package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleConfirmAccount(Package $package): BusinessAccount
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

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function wholesaleConfirmAddress(array $overrides = []): array
{
    return [
        'contact_name' => 'Karim Uddin',
        'contact_mobile' => '01712345678',
        'line_1' => 'House 12, Road 5',
        'area' => 'Mirpur',
        'city' => 'Dhaka',
        'postcode' => '1216',
        ...$overrides,
    ];
}

/**
 * The fingerprint of Karim's checkout as the server prices it right now.
 */
function wholesaleConfirmFingerprint(): string
{
    $account = test()->karim;

    return app(PriceCheckout::class)->quote(app(OpenCart::class)->find($account->owner, $account), $account)->fingerprint();
}

function wholesaleConfirmCart(): Cart
{
    return Cart::query()->where('business_account_id', test()->karim->id)->sole();
}

/**
 * @param  array<string, mixed>  $payload
 */
function wholesaleConfirmPost(array $payload = []): TestResponse
{
    return test()->post(route('wholesale.checkout.confirmation.store'), [
        'payment_method' => 'sslcommerz',
        'fingerprint' => wholesaleConfirmFingerprint(),
        ...$payload,
    ]);
}

describe('payment method and summary', function () {
    it('offers the gateways that can take this payment, and not the wallet', function () {
        $this->get(route('wholesale.checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wholesale/checkout')
                ->where('checkout.payment_methods', [['name' => 'sslcommerz', 'label' => 'SSLCommerz']])
                ->where('checkout.fingerprint', wholesaleConfirmFingerprint())
                ->where('checkout.confirmation', null)
                ->where('checkout.ready_to_confirm', true)
                ->where('checkout.total.minor_units', 2000000));
    });
});

describe('confirming', function () {
    it('confirms the summary the person saw, with the payment method they chose', function () {
        $fingerprint = wholesaleConfirmFingerprint();

        wholesaleConfirmPost()->assertSessionHasNoErrors()->assertRedirect();

        $cart = wholesaleConfirmCart();

        expect($cart->payment_method)->toBe('sslcommerz')
            ->and($cart->confirmed_at)->not->toBeNull()
            ->and($cart->confirmed_fingerprint)->toBe($fingerprint)
            ->and($cart->confirmed_total_minor)->toBe(2000000)
            ->and($cart->currency_code)->toBe('BDT');

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.confirmation.status', 'confirmed')
                ->where('checkout.confirmation.payment_method.label', 'SSLCommerz')
                ->where('checkout.confirmation.total.minor_units', 2000000));
    });

    it('leaves one confirmation when the same summary is confirmed again', function () {
        wholesaleConfirmPost()->assertSessionHasNoErrors();
        $first = wholesaleConfirmCart()->confirmed_at;

        $this->travel(5)->minutes();

        wholesaleConfirmPost()->assertSessionHasNoErrors();

        expect(wholesaleConfirmCart()->confirmed_at?->equalTo($first))->toBeTrue();
    });

    it('refuses a payment method that is not on offer', function (string $method) {
        wholesaleConfirmPost(['payment_method' => $method])
            ->assertSessionHasErrors(['payment_method' => __('wholesale.refused.payment_method_unavailable')]);

        expect(wholesaleConfirmCart()->confirmed_at)->toBeNull();
    })->with([
        'a gateway that is switched off' => ['bkash'],
        'the wallet' => ['wallet'],
        'no gateway at all' => ['cash-in-hand'],
    ]);

    it('requires a payment method and the summary fingerprint', function () {
        wholesaleConfirmPost(['payment_method' => ''])->assertSessionHasErrors('payment_method');
        wholesaleConfirmPost(['fingerprint' => 'short'])->assertSessionHasErrors('fingerprint');

        expect(wholesaleConfirmCart()->confirmed_at)->toBeNull();
    });

    it('keeps the server\'s figures whatever the browser sends', function () {
        wholesaleConfirmPost([
            'total' => 1,
            'confirmed_total_minor' => 1,
            'confirmed_at' => '2020-01-01 00:00:00',
            'currency_code' => 'USD',
            'discount' => 1999999,
        ])->assertSessionHasNoErrors();

        $cart = wholesaleConfirmCart();

        expect($cart->confirmed_total_minor)->toBe(2000000)
            ->and($cart->currency_code)->toBe('BDT')
            ->and($cart->confirmed_at?->isAfter(now()->subMinute()))->toBeTrue();
    });

    it('withdraws a confirmation so the order can change', function () {
        wholesaleConfirmPost()->assertSessionHasNoErrors();

        $this->delete(route('wholesale.checkout.confirmation.destroy'))->assertRedirect();

        $cart = wholesaleConfirmCart();

        expect($cart->confirmed_at)->toBeNull()
            ->and($cart->payment_method)->toBeNull()
            ->and($cart->confirmed_fingerprint)->toBeNull();
    });
});

describe('a stale summary', function () {
    it('refuses a summary that changed while the page was open, and confirms nothing', function (Closure $change) {
        $seen = wholesaleConfirmFingerprint();

        $change();

        wholesaleConfirmPost(['fingerprint' => $seen])
            ->assertSessionHasErrors(['fingerprint' => __('wholesale.refused.checkout_changed')]);

        expect(wholesaleConfirmCart()->confirmed_at)->toBeNull();
    })->with([
        'a delivery charge' => [fn () => FeeRule::create([
            'fee_type' => FeeType::WholesaleDelivery,
            'amount_minor' => 10000,
            'currency_code' => 'BDT',
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ])],
        'a tax rule' => [function () {
            TaxRate::factory()->create();
            TaxRule::factory()->create();
        }],
        'the shipping address' => [fn () => app(SaveCheckoutAddress::class)
            ->handle(test()->karim->owner, AddressType::Shipping, wholesaleConfirmAddress(['city' => 'Gazipur']))],
        'the quantity' => [fn () => test()->post(route('wholesale.cart.items.store'), ['product' => test()->kettle->slug, 'quantity' => 12])],
        'a coupon' => [function () {
            $coupon = Coupon::create([
                'code' => 'BULK'.Str::upper(Str::random(6)),
                'name' => 'Bulk buyer',
                'discount_type' => DiscountType::Fixed,
                'value' => 50000,
                'currency_code' => 'BDT',
                'applies_to' => CouponScope::WholesaleOrder,
                'per_account_limit' => 1,
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            test()->put(route('wholesale.checkout.coupon.apply'), ['code' => $coupon->code])->assertSessionHasNoErrors();
        }],
    ]);

    it('shows a confirmation that no longer stands, and takes a new one', function () {
        wholesaleConfirmPost()->assertSessionHasNoErrors();

        $this->post(route('wholesale.cart.items.store'), ['product' => $this->kettle->slug, 'quantity' => 12])
            ->assertSessionHasNoErrors();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.confirmation.status', 'stale')
                ->where('checkout.confirmation.total.minor_units', 2000000)
                ->where('checkout.total.minor_units', 2400000));

        wholesaleConfirmPost()->assertSessionHasNoErrors();

        expect(wholesaleConfirmCart()->confirmed_total_minor)->toBe(2400000);

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page->where('checkout.confirmation.status', 'confirmed'));
    });

    it('sends a changed price back to the cart before anything can be confirmed again', function () {
        wholesaleConfirmPost()->assertSessionHasNoErrors();

        Product::query()->whereKey($this->kettle->id)->update(['wholesale_price_minor' => 210000]);

        $this->get(route('wholesale.checkout.show'))->assertRedirect(route('wholesale.cart.show'));

        wholesaleConfirmPost()->assertSessionHasErrors(['cart' => __('wholesale.refused.cart_not_ready')]);

        $this->post(route('wholesale.cart.prices.accept'))->assertSessionHasNoErrors();

        $this->get(route('wholesale.checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.confirmation.status', 'stale')
                ->where('checkout.total.minor_units', 2100000));
    });

    it('will not confirm without both addresses', function () {
        UserAddress::query()->where('type', AddressType::Shipping->value)->delete();

        wholesaleConfirmPost()->assertSessionHasErrors(['addresses' => __('wholesale.refused.addresses_missing')]);

        expect(wholesaleConfirmCart()->confirmed_at)->toBeNull();
    });

    it('will not confirm when stock no longer covers the order', function () {
        app(StockLedger::class)->move($this->stock, StockBucket::Available, null, 45, StockMovementType::Adjustment);

        wholesaleConfirmPost()->assertSessionHasErrors(['cart' => __('wholesale.refused.cart_not_ready')]);

        expect(wholesaleConfirmCart()->confirmed_at)->toBeNull();
    });
});

describe('who can confirm', function () {
    it('only ever confirms the requester\'s own cart', function () {
        $fingerprint = wholesaleConfirmFingerprint();
        $rahim = wholesaleConfirmAccount($this->package);

        $this->actingAs($rahim->owner)
            ->post(route('wholesale.checkout.confirmation.store'), ['payment_method' => 'sslcommerz', 'fingerprint' => $fingerprint])
            ->assertSessionHasErrors('cart');

        $this->delete(route('wholesale.checkout.confirmation.destroy'))->assertRedirect();

        expect(wholesaleConfirmCart()->confirmed_at)->toBeNull()
            ->and(Cart::query()->where('business_account_id', $rahim->id)->exists())->toBeFalse();
    });

    it('refuses an account whose package does not include wholesale purchasing', function () {
        $closedPackage = wholesaleConfirmPackage();
        $closedPackage->features()->create(['feature' => PackageFeature::WholesaleEnabled->value, 'value' => '0']);
        $closed = wholesaleConfirmAccount($closedPackage);

        $this->actingAs($closed->owner)
            ->post(route('wholesale.checkout.confirmation.store'), ['payment_method' => 'sslcommerz', 'fingerprint' => str_repeat('a', 64)])
            ->assertForbidden();
    });
});

describe('the schema', function () {
    it('refuses a confirmation that is only half recorded', function () {
        expect(fn () => DB::table('carts')->where('business_account_id', $this->karim->id)->update(['confirmed_at' => now()]))
            ->toThrow(QueryException::class);
    });
});
