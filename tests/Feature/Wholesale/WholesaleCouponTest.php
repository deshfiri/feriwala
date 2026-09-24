<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Models\Coupon;
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
use App\Domain\Wholesale\Models\Cart;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Coupons at ERP wholesale checkout (P4-6, §9, §14).
 *
 * A wholesale code comes off the goods subtotal the server has just priced, is
 * checked again on every render, and never spends itself by being looked at. The
 * browser sends a code and nothing else: a discount, total or subtotal it sends
 * is ignored. Activation codes and wholesale codes do not cross over.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->package = wholesaleCouponPackage('growth');
    $this->otherPackage = wholesaleCouponPackage('enterprise');
    $this->karim = wholesaleCouponAccount($this->package);

    $category = Category::create(['name' => 'Kitchen']);
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    // 2,000 taka each, so ten of them are 20,000 taka.
    $this->kettle = Product::create([
        'name' => 'Electric kettle',
        'sku' => 'FW-KT',
        'category_id' => $category->id,
        'base_cost' => Money::fromDecimal('500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2000.00', Currency::BDT),
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
});

function wholesaleCouponPackage(string $slug): Package
{
    return Package::create([
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'name' => Str::title($slug),
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleCouponAccount(Package $package): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

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

    return $account->refresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wholesaleCouponCode(array $overrides = []): Coupon
{
    return Coupon::create([
        'code' => 'BULK'.Str::upper(Str::random(6)),
        'name' => 'Bulk buyer',
        'discount_type' => DiscountType::Percentage,
        'value' => 1000,
        'currency_code' => 'BDT',
        'applies_to' => CouponScope::WholesaleOrder,
        'per_account_limit' => 1,
        'effective_from' => now()->subDay(),
        'is_active' => true,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function wholesaleCouponApply(array $payload): TestResponse
{
    return test()->put(route('wholesale.checkout.coupon.apply'), $payload);
}

function wholesaleCouponCart(BusinessAccount $account): Cart
{
    return Cart::query()->where('business_account_id', $account->id)->sole();
}

describe('applying a code', function () {
    it('takes the discount off the subtotal the server priced, and shows the total that follows', function () {
        $coupon = wholesaleCouponCode();

        wholesaleCouponApply(['code' => Str::lower($coupon->code)])->assertSessionHasNoErrors()->assertRedirect();

        // Kept as the coupon's own code, whatever capitals were typed.
        expect(wholesaleCouponCart($this->karim)->coupon_code)->toBe($coupon->code);

        $this->get(route('wholesale.checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wholesale/checkout')
                ->where('checkout.lines.0.quantity', 10)
                ->where('checkout.lines.0.unit_price.amount', '2000.00')
                ->where('checkout.subtotal.amount', '20000.00')
                ->where('checkout.coupon.accepted', true)
                ->where('checkout.coupon.code', $coupon->code)
                ->where('checkout.discount.amount', '2000.00')
                ->where('checkout.total.amount', '18000.00')
                ->missing('checkout.lines.0.base_cost'));
    });

    it('ignores every figure the browser sends alongside the code', function () {
        $coupon = wholesaleCouponCode(['discount_type' => DiscountType::Fixed, 'value' => 50000]);

        wholesaleCouponApply([
            'code' => $coupon->code,
            'discount' => 1999999,
            'discount_minor' => 1999999,
            'subtotal' => 1,
            'total' => 1,
            'tax' => 0,
            'value' => 10000,
        ])->assertSessionHasNoErrors();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.subtotal.amount', '20000.00')
                ->where('checkout.discount.amount', '500.00')
                ->where('checkout.total.amount', '19500.00'));
    });

    it('refuses a code that does not apply, says why, and keeps nothing', function (Closure $makeCoupon, string $reason) {
        $code = $makeCoupon();

        wholesaleCouponApply(['code' => $code])
            ->assertSessionHasErrors(['code' => __($reason)]);

        expect(wholesaleCouponCart($this->karim)->coupon_code)->toBeNull();
    })->with([
        'unknown' => [fn () => 'NOSUCHCODE', 'billing.coupons.refused.unknown'],
        'not started' => [fn () => wholesaleCouponCode(['effective_from' => now()->addDay()])->code, 'billing.coupons.refused.not_started'],
        'expired' => [fn () => wholesaleCouponCode(['effective_from' => now()->subMonth(), 'effective_until' => now()->subDay()])->code, 'billing.coupons.refused.expired'],
        'an activation code' => [fn () => wholesaleCouponCode(['applies_to' => CouponScope::Fees])->code, 'billing.coupons.refused.not_for_wholesale'],
        'another package' => [fn () => wholesaleCouponCode(['package_id' => test()->otherPackage->id])->code, 'billing.coupons.refused.other_package'],
        'another currency' => [fn () => wholesaleCouponCode(['currency_code' => 'USD'])->code, 'billing.coupons.refused.currency'],
        'below the minimum spend' => [fn () => wholesaleCouponCode(['minimum_spend' => Money::fromDecimal('20000.01', Currency::BDT)])->code, 'billing.coupons.refused.minimum_spend'],
        'used up' => [fn () => wholesaleCouponCode(['usage_limit' => 1, 'redeemed_count' => 1])->code, 'billing.coupons.refused.exhausted'],
    ]);

    it('accepts a code restricted to the package the account is on, and a minimum spend met exactly', function () {
        $coupon = wholesaleCouponCode(['package_id' => $this->package->id, 'minimum_spend' => Money::fromDecimal('20000.00', Currency::BDT)]);

        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();

        expect(wholesaleCouponCart($this->karim)->coupon_code)->toBe($coupon->code);
    });

    it('requires a code', function () {
        wholesaleCouponApply(['code' => ''])->assertSessionHasErrors('code');
        wholesaleCouponApply(['code' => str_repeat('A', 65)])->assertSessionHasErrors('code');
    });

    it('refuses a code while the cart is not ready for checkout', function () {
        $coupon = wholesaleCouponCode();

        // Somebody else took the stock, so ten is now more than can be ordered.
        app(StockLedger::class)->move($this->stock, StockBucket::Available, null, 45, StockMovementType::Adjustment);

        wholesaleCouponApply(['code' => $coupon->code])
            ->assertSessionHasErrors(['cart' => __('wholesale.refused.cart_not_ready')]);

        expect(wholesaleCouponCart($this->karim)->coupon_code)->toBeNull();
    });

    it('does not spend the code by applying it or by looking at the checkout', function () {
        $coupon = wholesaleCouponCode(['usage_limit' => 1]);

        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();
        $this->get(route('wholesale.checkout.show'))->assertOk();
        $this->get(route('wholesale.checkout.show'))->assertOk();

        expect($coupon->refresh()->redeemed_count)->toBe(0)
            ->and($coupon->redemptions()->count())->toBe(0);
    });

    it('removes a code', function () {
        $coupon = wholesaleCouponCode();
        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();

        $this->delete(route('wholesale.checkout.coupon.remove'))->assertRedirect();

        expect(wholesaleCouponCart($this->karim)->coupon_code)->toBeNull();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.coupon', null)
                ->where('checkout.discount.amount', '0.00')
                ->where('checkout.total.amount', '20000.00'));
    });
});

describe('a code checked again on every render', function () {
    it('stops taking anything off once it no longer applies, and says why', function () {
        $coupon = wholesaleCouponCode();
        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();

        $coupon->forceFill(['effective_until' => now()->subMinute()])->save();

        $this->get(route('wholesale.checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.coupon.entered', $coupon->code)
                ->where('checkout.coupon.accepted', false)
                ->where('checkout.coupon.reason', __('billing.coupons.refused.expired'))
                ->where('checkout.discount.amount', '0.00')
                ->where('checkout.total.amount', '20000.00'));
    });

    it('works a percentage out again when the quantity changes', function () {
        $coupon = wholesaleCouponCode();
        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();

        $this->post(route('wholesale.cart.items.store'), ['product' => $this->kettle->slug, 'quantity' => 5])
            ->assertSessionHasNoErrors();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.subtotal.amount', '10000.00')
                ->where('checkout.discount.amount', '1000.00')
                ->where('checkout.total.amount', '9000.00'));
    });

    it('never takes more off than the subtotal', function () {
        $coupon = wholesaleCouponCode(['discount_type' => DiscountType::Fixed, 'value' => 5000000]);
        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.discount.amount', '20000.00')
                ->where('checkout.total.amount', '0.00'));
    });
});

describe('who can check out', function () {
    it('sends a cart that is not ready back to the cart', function () {
        app(StockLedger::class)->move($this->stock, StockBucket::Available, null, 45, StockMovementType::Adjustment);

        $this->get(route('wholesale.checkout.show'))->assertRedirect(route('wholesale.cart.show'));
    });

    it('sends a person with no cart back to the cart', function () {
        $rahim = wholesaleCouponAccount($this->package);

        $this->actingAs($rahim->owner)
            ->get(route('wholesale.checkout.show'))
            ->assertRedirect(route('wholesale.cart.show'));
    });

    it('only ever reads and changes the requester\'s own cart', function () {
        $coupon = wholesaleCouponCode(['per_account_limit' => null]);
        $rahim = wholesaleCouponAccount($this->package);

        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasNoErrors();

        // Rahim has no cart: applying refuses, and removing leaves Karim's code alone.
        $this->actingAs($rahim->owner);
        wholesaleCouponApply(['code' => $coupon->code])->assertSessionHasErrors('cart');
        $this->delete(route('wholesale.checkout.coupon.remove'))->assertRedirect();

        expect(wholesaleCouponCart($this->karim)->coupon_code)->toBe($coupon->code)
            ->and(Cart::query()->where('business_account_id', $rahim->id)->exists())->toBeFalse();
    });

    it('refuses an account whose package does not include wholesale purchasing', function () {
        $this->otherPackage->features()->create(['feature' => PackageFeature::WholesaleEnabled->value, 'value' => '0']);
        $closed = wholesaleCouponAccount($this->otherPackage);

        $this->actingAs($closed->owner);

        wholesaleCouponApply(['code' => wholesaleCouponCode()->code])->assertForbidden();
        $this->get(route('wholesale.checkout.show'))->assertRedirect(route('wholesale.cart.show'));
    });

    it('requires sign-in', function () {
        auth()->logout();

        $this->get(route('wholesale.checkout.show'))->assertRedirect(route('login'));
        $this->put(route('wholesale.checkout.coupon.apply'), ['code' => 'X'])->assertRedirect(route('login'));
    });
});

describe('the one coupon engine', function () {
    it('keeps a wholesale code off an activation quote', function () {
        $coupon = wholesaleCouponCode();

        $outcome = app(CouponValidator::class)->validate(
            $coupon->code,
            $this->karim,
            $this->package,
            Money::fromDecimal('1000.00', Currency::BDT),
            Money::fromDecimal('5000.00', Currency::BDT),
        );

        expect($outcome->isAccepted)->toBeFalse()
            ->and($outcome->reason)->toBe('billing.coupons.refused.wholesale_only');
    });

    it('stops a package-restricted code once the account leaves that package', function () {
        $coupon = wholesaleCouponCode(['package_id' => $this->package->id]);

        UserPackage::query()->where('business_account_id', $this->karim->id)->update(['expires_at' => now()->subMinute()]);

        $outcome = app(CouponValidator::class)->validateForWholesale($coupon->code, $this->karim->refresh(), Money::fromDecimal('20000.00', Currency::BDT));

        expect($outcome->reason)->toBe('billing.coupons.refused.other_package');
    });
});
