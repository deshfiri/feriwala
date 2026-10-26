<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\WholesalePaymentModel;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Models\CartItem;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Account Type (Conditional / Non-Conditional) at wholesale checkout (D34).
 *
 * ERP wholesale is a Partner buying for themself or their own stock, so every
 * account — Conditional or not — pays the whole server-calculated total through
 * an enabled gateway (or the wallet), and a zero delivery charge never makes an
 * order "paid". No resale amount is asked for or kept.
 *
 * Orders placed before D34, under the delivery-only rule, carry no payment model
 * and keep the behaviour they were stored with.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    Http::fake(fn (ClientRequest $request) => str_contains($request->url(), 'gwprocess')
        ? Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1'])
        : Http::response(['status' => 'VALID', 'currency_amount' => '0.00', 'currency_type' => 'BDT']));

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

describe('adding a line to the cart', function () {
    it('asks a Non-Conditional account for no resale amount, and keeps none even when one is sent', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])
            ->assertSessionHasNoErrors();

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '12000.00'])
            ->assertSessionHasNoErrors();

        expect(CartItem::query()->where('product_id', $this->kettle->id)->sole()->resale_amount)->toBeNull();
    });

    it('stores no resale amount for a Conditional account either', function () {
        $account = ncoAccount($this->package);

        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1, 'resale_amount' => '12000.00'])
            ->assertSessionHasNoErrors();

        expect(CartItem::query()->where('product_id', $this->kettle->id)->sole()->resale_amount)->toBeNull();
    });

    it('clears a resale amount an older cart line still carries when it is updated', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])->assertSessionHasNoErrors();

        $line = CartItem::query()->where('product_id', $this->kettle->id)->sole();
        $line->forceFill(['resale_amount' => Money::fromDecimal('12000.00', Currency::BDT)])->save();

        test()->actingAs($account->owner)
            ->patch(route('wholesale.cart.items.update', $line->public_id ?? $line->id), ['quantity' => 2])
            ->assertSessionHasNoErrors();

        expect($line->refresh()->quantity)->toBe(2)
            ->and($line->resale_amount)->toBeNull();
    });
});

describe('placing the order (D34: always paid in full)', function () {
    it('charges a Non-Conditional account the whole total through the gateway, never just the delivery charge', function () {
        FeeRule::create([
            'fee_type' => FeeType::WholesaleDelivery,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('120.00', Currency::BDT),
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();
        $line = $order->items()->sole();

        expect($order->account_type)->toBe(AccountType::NonConditional)
            ->and($order->wholesale_payment_model)->toBe(WholesalePaymentModel::Full)
            ->and($order->total->toDecimal())->toBe('20120.00')
            ->and($order->payment->purpose)->toBe(PaymentPurpose::WholesaleOrder)
            ->and($order->payment->amount->toDecimal())->toBe('20120.00')
            ->and($order->payment->gateway)->toBe('sslcommerz')
            ->and($order->payment->allocations->pluck('type')->all())->toBe([AllocationType::WholesaleGoods, AllocationType::DeliveryCharge])
            // Not settled by anything but the gateway's own verified answer.
            ->and($order->payment->status->isSettled())->toBeFalse()
            ->and($order->status)->toBe(OrderStatus::PaymentPending)
            // No resale amount, and nothing to recover from a COD amount later.
            ->and($line->resale_amount)->toBeNull()
            ->and($line->defersProductCost())->toBeFalse();
    });

    it('does not treat a zero delivery charge as a paid order', function () {
        // No delivery fee rule at all: the delivery charge is nothing.
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        expect($order->delivery->isPositive())->toBeFalse()
            ->and($order->payment->amount->toDecimal())->toBe('20000.00')
            ->and($order->payment->status->isSettled())->toBeFalse()
            ->and($order->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->paid_at)->toBeNull();
    });

    it('opens a gateway session for the whole amount rather than skipping the gateway', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'gwprocess')
            && (string) ($request['total_amount'] ?? '') === '20000.00');

        expect(Order::query()->where('business_account_id', $account->id)->sole()->payment->status)
            ->toBe(PaymentStatus::Initiated);
    });

    it('charges a Non-Conditional account the whole total from the wallet too', function () {
        FeeRule::create([
            'fee_type' => FeeType::WholesaleDelivery,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('120.00', Currency::BDT),
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        $wallet = websiteTestWallet($account, '25000.00');
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        app(ConfirmCheckout::class)->handle($account->owner, $account, 'wallet', ncoFingerprint($account));

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        expect($order->payment->amount->toDecimal())->toBe('20120.00')
            ->and($order->payment->gateway)->toBe('wallet')
            ->and($order->payment->status->value)->toBe('paid')
            ->and($wallet->refresh()->total->toDecimal())->toBe('4880.00')
            ->and($order->items()->sole()->defersProductCost())->toBeFalse();
    });

    it('charges a Conditional account the full product and delivery cost, exactly as before', function () {
        $account = ncoAccount($this->package);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 2])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        expect($order->account_type)->toBe(AccountType::Conditional)
            ->and($order->wholesale_payment_model)->toBe(WholesalePaymentModel::Full)
            ->and($order->payment->amount->toDecimal())->toBe('20000.00')
            ->and($order->payment->allocations->pluck('type')->all())->toBe([AllocationType::WholesaleGoods])
            ->and($order->items()->sole()->resale_amount)->toBeNull();
    });

    it('places a Non-Conditional order from a line that still carries an old resale amount, ignoring it', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])->assertSessionHasNoErrors();
        CartItem::query()->where('product_id', $this->kettle->id)->update(['resale_amount' => '12000.00']);
        ncoAddressAndConfirm($account);

        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $line = Order::query()->where('business_account_id', $account->id)->sole()->items()->sole();

        expect($line->resale_amount)->toBeNull();
    });

    it('freezes the order\'s account_type snapshot: a later change to the account never rewrites a placed order', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);
        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        $account->forceFill(['account_type' => AccountType::Conditional])->save();

        expect($order->fresh()->account_type)->toBe(AccountType::NonConditional)
            ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['account_type' => 'conditional']))
            ->toThrow(QueryException::class);
    });

    it('freezes the payment model: it cannot be changed or cleared once the order exists', function () {
        $account = ncoAccount($this->package, ['account_type' => AccountType::NonConditional->value]);
        ncoAddToCart($account, ['product' => $this->kettle->slug, 'quantity' => 1])->assertSessionHasNoErrors();
        ncoAddressAndConfirm($account);
        ncoPlaceOrder($account)->assertSessionHasNoErrors();

        $order = Order::query()->where('business_account_id', $account->id)->sole();

        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['wholesale_payment_model' => null]))
            ->toThrow(QueryException::class);
    });
});

/**
 * An ERP wholesale order waiting for payment, with the payment already recorded
 * for `$amount` — built directly, the way the earlier rule left one behind.
 *
 * @return array{0: Order, 1: OrderItem}
 */
function ncoPendingOrder(AccountType $type, string $amount, ?WholesalePaymentModel $model): array
{
    $account = ncoAccount(test()->package, ['account_type' => $type->value]);

    $payment = Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::WholesaleOrder,
        'status' => PaymentStatus::Draft,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal($amount, Currency::BDT),
        'gateway' => 'sslcommerz',
        'expires_at' => now()->addHour(),
    ]);

    $order = Order::factory()->create([
        'business_account_id' => $account->id,
        'account_type' => $type,
        'wholesale_payment_model' => $model,
        'payment_id' => $payment->id,
    ]);

    $line = OrderItem::create([
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => test()->kettle->id,
        'sku' => test()->kettle->sku,
        'product_name' => test()->kettle->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'resale_amount' => $model === null ? Money::fromDecimal('3200.00', Currency::BDT) : null,
        'created_at' => now(),
    ]);

    return [$order->refresh(), $line];
}

describe('orders placed before D34 (delivery-only rule)', function () {
    it('keep deferring the product cost and recovering it from the proceeds, by what they stored', function () {
        [$order, $line] = ncoPendingOrder(AccountType::NonConditional, '120.00', null);

        expect($order->wholesale_payment_model)->toBeNull()
            ->and($order->usesFullPaymentModel())->toBeFalse()
            ->and($line->defersProductCost())->toBeTrue()
            ->and($line->recoverableAmount()->toDecimal())->toBe('2600.00')
            ->and($line->resale_amount->toDecimal())->toBe('3200.00');
    });

    it('are not changed by the account becoming Conditional later', function () {
        [$order, $line] = ncoPendingOrder(AccountType::NonConditional, '120.00', null);

        $order->businessAccount->forceFill(['account_type' => AccountType::Conditional])->save();

        expect($line->refresh()->order->refresh()->isNonConditional())->toBeTrue()
            ->and($line->defersProductCost())->toBeTrue();
    });

    it('still settle a pending zero-amount payment without a gateway, as they always did', function () {
        [$order] = ncoPendingOrder(AccountType::NonConditional, '0.00', null);

        test()->actingAs($order->businessAccount->owner)
            ->post(route('wholesale.orders.payment.store', $order->public_id))
            ->assertSessionHasNoErrors();

        expect($order->payment->refresh()->status->value)->toBe('paid');
    });

    it('a new full-payment order never settles that way: a zero payment is refused, not marked paid', function () {
        [$order] = ncoPendingOrder(AccountType::NonConditional, '0.00', WholesalePaymentModel::Full);

        test()->actingAs($order->businessAccount->owner)
            ->post(route('wholesale.orders.payment.store', $order->public_id));

        expect($order->payment->refresh()->status->isSettled())->toBeFalse()
            ->and($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });

    it('differ from a full-payment order of the same account type, which defers nothing', function () {
        [, $legacyLine] = ncoPendingOrder(AccountType::NonConditional, '120.00', null);
        [, $fullLine] = ncoPendingOrder(AccountType::NonConditional, '2600.00', WholesalePaymentModel::Full);

        expect($legacyLine->defersProductCost())->toBeTrue()
            ->and($fullLine->defersProductCost())->toBeFalse();
    });
});
