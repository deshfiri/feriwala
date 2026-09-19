<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RedemptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\CouponRedemption;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\ReservationWindows;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\ExpireUnpaidOrders;
use App\Domain\Order\Actions\PlaceWholesaleOrder;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Support\Concurrency\DistributedLock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Paying for an ERP wholesale order (P4-9, P4-10, P4-11, P4-14, §14, §19.1, §26.4).
 *
 * A confirmed checkout becomes exactly one order waiting for its one payment, with
 * its stock reserved for the ordering account and every line snapshotted as the
 * server priced it. The payment's outcome decides the rest, once, however many
 * times the gateway says so:
 *
 *   - settled inside the window — the stock is committed, the same order moves to
 *     paid, and only then is the invoice issued;
 *   - failed, cancelled or out of time — the order is cancelled and its stock given
 *     back exactly once;
 *   - confirmed after the window — reconciliation, never a paid or held order;
 *   - settled but its stock is gone — held for review, with no invoice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    // One closure for every gateway call, reading what the test says the gateway
    // answers now: a second Http::fake() would be merged and silently ignored. An
    // object, so a helper can change the answer through test().
    $this->validation = new ArrayObject(['status' => 'VALID', 'currency_amount' => '20000.00', 'currency_type' => 'BDT']);
    $this->gateway = new ArrayObject(['reachable' => true]);

    Http::fake(function (ClientRequest $request) {
        if (str_contains($request->url(), 'gwprocess')) {
            if (! $this->gateway['reachable']) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']);
        }

        return Http::response($this->validation->getArrayCopy());
    });

    $this->package = wholesaleOrderPackage();
    $this->karim = wholesaleOrderAccount($this->package);
    $this->rahim = wholesaleOrderAccount($this->package);

    $this->category = Category::create(['name' => 'Kitchen']);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    // Ten kettles at 2,000 taka: 20,000 taka.
    $this->kettle = wholesaleOrderProduct('Electric kettle', 'FW-KT', 200000);
    $this->stock = wholesaleOrderStock($this->kettle, 50);

    wholesaleOrderCart($this->karim, [[$this->kettle, 10]]);
});

function wholesaleOrderPackage(): Package
{
    return Package::create([
        'slug' => 'order-'.Str::lower(Str::random(8)),
        'name' => 'Order package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleOrderAccount(Package $package): BusinessAccount
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

function wholesaleOrderProduct(string $name, string $sku, int $priceMinor): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => test()->category->id,
        'base_cost_minor' => 50000,
        'wholesale_price_minor' => $priceMinor,
        'min_order_quantity' => 1,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);
}

function wholesaleOrderStock(Product $product, int $units): StockItem
{
    $item = StockItem::create(['warehouse_id' => test()->warehouse->id, 'product_id' => $product->id]);
    app(StockLedger::class)->move($item, null, StockBucket::Available, $units, StockMovementType::Adjustment);

    return $item;
}

/**
 * Fill an account owner's cart, give it both addresses and confirm the checkout.
 *
 * @param  array<int, array{0: Product, 1: int}>  $lines
 */
function wholesaleOrderCart(BusinessAccount $account, array $lines): void
{
    test()->actingAs($account->owner);

    foreach ($lines as [$product, $quantity]) {
        test()->post(route('wholesale.cart.items.store'), ['product' => $product->slug, 'quantity' => $quantity])
            ->assertSessionHasNoErrors();
    }

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

    wholesaleOrderConfirm($account);
}

function wholesaleOrderConfirm(BusinessAccount $account): void
{
    app(ConfirmCheckout::class)->handle($account->owner, $account, 'sslcommerz', wholesaleOrderFingerprint($account));
}

function wholesaleOrderFingerprint(?BusinessAccount $account = null): string
{
    $account ??= test()->karim;

    return app(PriceCheckout::class)->quote(app(OpenCart::class)->find($account->owner, $account), $account)->fingerprint();
}

function wholesaleOrderCartOf(?BusinessAccount $account = null): Cart
{
    return Cart::query()->where('business_account_id', ($account ?? test()->karim)->id)->sole();
}

/**
 * @param  array<string, mixed>  $payload
 */
function wholesaleOrderPlace(array $payload = []): TestResponse
{
    return test()->post(route('wholesale.orders.store'), [
        'fingerprint' => wholesaleOrderFingerprint(),
        ...$payload,
    ]);
}

/**
 * Place Karim's order, and have the gateway answer about its payment from now on.
 */
function wholesaleOrderPlaced(): Order
{
    wholesaleOrderPlace()->assertSessionHasNoErrors()->assertRedirect('https://pay.test/go');

    $order = Order::query()->where('business_account_id', test()->karim->id)->sole();
    test()->validation['tran_id'] = $order->payment?->reference;

    return $order;
}

/**
 * A notification signed the way SSLCommerz signs it, with the store password "pass".
 *
 * @return array<string, string>
 */
function wholesaleOrderIpn(string $reference, string $valId = 'val-1'): array
{
    $fields = ['tran_id' => $reference, 'val_id' => $valId, 'status' => 'VALID'];

    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed))),
    ];
}

function wholesaleOrderNotify(Order $order): TestResponse
{
    return test()->post(route('webhooks.payment', 'sslcommerz'), wholesaleOrderIpn((string) $order->payment?->reference));
}

function wholesaleOrderMovements(StockMovementType $type): int
{
    return DB::table('stock_movements')->where('stock_item_id', test()->stock->id)->where('type', $type->value)->count();
}

describe('placing the order (P4-9, P4-10)', function () {
    it('turns the confirmed checkout into one order waiting for its payment, with its stock reserved', function () {
        $order = wholesaleOrderPlaced();
        $payment = $order->payment;
        $line = $order->items()->sole();
        $reservation = $line->stockReservation;

        expect($order->source->value)->toBe('erp_wholesale')
            ->and($order->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->placed_by)->toBe($this->karim->owner_id)
            ->and($order->cart_id)->toBe(wholesaleOrderCartOf()->id)
            ->and($order->total_minor->minorUnits)->toBe(2000000)
            ->and($order->subtotal_minor->minorUnits)->toBe(2000000)
            ->and($order->shipping_address['city'])->toBe('Dhaka')
            ->and($order->customer['business_name'])->toBe($this->karim->name);

        expect($line->sku)->toBe('FW-KT')
            ->and($line->product_name)->toBe('Electric kettle')
            ->and($line->quantity)->toBe(10)
            ->and($line->unit_price_minor->minorUnits)->toBe(200000)
            ->and($line->line_total_minor->minorUnits)->toBe(2000000);

        // Reserved through the P3 service, for the ordering account, all from one warehouse.
        expect($reservation)->not->toBeNull()
            ->and($reservation->status)->toBe(StockReservationStatus::Active)
            ->and($reservation->business_account_id)->toBe($this->karim->id)
            ->and($reservation->reference)->toBe($order->reference.'-L1')
            ->and($this->stock->refresh()->reserved)->toBe(10)
            ->and($this->stock->available)->toBe(40);

        // One payment for it, opened at the gateway, closing a margin before the stock is released.
        expect($payment->purpose)->toBe(PaymentPurpose::WholesaleOrder)
            ->and($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($payment->amount_minor->minorUnits)->toBe(2000000)
            ->and($payment->gateway)->toBe('sslcommerz')
            ->and($payment->gateway_mode)->toBe('sandbox')
            ->and($payment->payable_id)->toBe($order->id)
            ->and($payment->expires_at->equalTo($reservation->expires_at->subSeconds(PlaceWholesaleOrder::SETTLEMENT_MARGIN_SECONDS)))->toBeTrue()
            ->and($payment->allocations->pluck('type')->all())->toBe([AllocationType::WholesaleGoods]);

        // No invoice for an order nobody has paid for (P4-11).
        expect(Invoice::query()->count())->toBe(0);

        $history = $order->statusHistory()->sole();

        expect($history->previous_status)->toBeNull()
            ->and($history->new_status)->toBe(OrderStatus::PaymentPending)
            ->and($history->source)->toBe(OrderStatusChangeSource::Checkout)
            ->and($history->changed_by)->toBe($this->karim->owner_id);
    });

    it('tells an Inertia visit to leave for the gateway with a full page load', function () {
        $this->withHeader('X-Inertia', 'true')
            ->post(route('wholesale.orders.store'), ['fingerprint' => wholesaleOrderFingerprint()])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://pay.test/go');
    });

    it('never charges a price, total, tax, discount or quantity sent by the browser', function () {
        wholesaleOrderPlace([
            'total' => 100,
            'total_minor' => 100,
            'unit_price_minor' => 1,
            'quantity' => 1,
            'discount_minor' => 1999900,
            'tax_minor' => 0,
            'status' => 'paid',
        ])->assertRedirect('https://pay.test/go');

        $order = Order::query()->sole();

        expect($order->total_minor->minorUnits)->toBe(2000000)
            ->and($order->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->items()->sole()->quantity)->toBe(10)
            ->and($order->payment?->amount_minor->minorUnits)->toBe(2000000);

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'gwprocess')
            && $request['total_amount'] === '20000.00');
    });

    it('snapshots each line\'s discount and tax, and the order adds up', function () {
        $coupon = Coupon::create([
            'code' => 'BULK500',
            'name' => 'Bulk buyer',
            'discount_type' => DiscountType::Fixed,
            'value' => 50000,
            'currency_code' => 'BDT',
            'applies_to' => CouponScope::WholesaleOrder,
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->put(route('wholesale.checkout.coupon.apply'), ['code' => $coupon->code])->assertSessionHasNoErrors();
        wholesaleOrderConfirm($this->karim);

        $order = wholesaleOrderPlaced();
        $line = $order->items()->sole();

        expect($order->discount_minor->minorUnits)->toBe(50000)
            ->and($order->coupon_code)->toBe('BULK500')
            ->and($order->total_minor->minorUnits)->toBe(1950000)
            ->and($line->discount_minor->minorUnits)->toBe(50000)
            ->and($line->line_total_minor->minorUnits)->toBe(1950000)
            ->and($order->payment?->amount_minor->minorUnits)->toBe(1950000)
            ->and($order->payment?->allocations->pluck('type')->all())->toBe([AllocationType::WholesaleGoods, AllocationType::Discount])
            ->and(CouponRedemption::query()->where('payment_id', $order->payment_id)->sole()->status)->toBe(RedemptionStatus::Reserved);
    });

    it('refuses a checkout nobody confirmed, keeping nothing', function () {
        app(ConfirmCheckout::class)->withdraw($this->karim->owner, $this->karim);

        wholesaleOrderPlace()->assertSessionHasErrors(['confirmation' => __('orders.refused.checkout_not_confirmed')]);

        expect(Order::query()->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0)
            ->and(StockReservation::query()->count())->toBe(0);
    });

    it('refuses a checkout that changed after it was confirmed', function () {
        $seen = wholesaleOrderFingerprint();

        // Somebody changes the quantity after confirming: the confirmation no longer stands.
        $this->post(route('wholesale.cart.items.store'), ['product' => $this->kettle->slug, 'quantity' => 12]);

        $this->post(route('wholesale.orders.store'), ['fingerprint' => $seen])
            ->assertSessionHasErrors(['fingerprint' => __('wholesale.refused.checkout_changed')]);

        expect(Order::query()->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0)
            ->and(StockReservation::query()->count())->toBe(0);
    });

    it('refuses a summary other than the one on the page', function () {
        $this->post(route('wholesale.orders.store'), ['fingerprint' => str_repeat('a', 64)])
            ->assertSessionHasErrors('fingerprint');

        expect(Order::query()->count())->toBe(0);
    });

    it('answers a repeated submission of one confirmation with the same order', function () {
        wholesaleOrderPlace()->assertRedirect('https://pay.test/go');
        wholesaleOrderPlace();
        wholesaleOrderPlace();

        expect(Order::query()->count())->toBe(1)
            ->and(Payment::query()->count())->toBe(1)
            ->and(StockReservation::query()->count())->toBe(1)
            ->and($this->stock->refresh()->reserved)->toBe(10);
    });

    it('refuses another order from a cart whose order is still waiting for payment', function () {
        wholesaleOrderPlaced();

        // A fresh confirmation of the same cart is a new confirmation…
        app(ConfirmCheckout::class)->withdraw($this->karim->owner, $this->karim);
        $this->travel(1)->seconds();
        wholesaleOrderConfirm($this->karim);

        // …but the cart already has an order waiting.
        wholesaleOrderPlace()->assertSessionHasErrors(['order' => __('orders.refused.awaiting_payment')]);

        expect(Order::query()->count())->toBe(1)
            ->and(StockReservation::query()->count())->toBe(1);
    });

    it('rolls everything back when one line\'s stock goes between the checkout and the order', function () {
        $teaSet = wholesaleOrderProduct('Tea set', 'FW-TS', 100000);
        $teaStock = wholesaleOrderStock($teaSet, 20);

        $this->post(route('wholesale.cart.items.store'), ['product' => $teaSet->slug, 'quantity' => 5])->assertSessionHasNoErrors();
        wholesaleOrderConfirm($this->karim);

        // The first line reserves; the second finds its stock gone.
        app()->instance(StockReservations::class, new class(app(StockLedger::class), app(DistributedLock::class), app(ReservationWindows::class), app(DatabaseManager::class)) extends StockReservations
        {
            public int $calls = 0;

            public function reserve(Product $product, ?ProductVariant $variant, int $quantity, ReservationKind $kind, string $reference, ?BusinessAccount $account = null): StockReservation
            {
                if (++$this->calls === 2) {
                    throw InventoryRefused::outOfStock($product->sku, $quantity, 0);
                }

                return parent::reserve($product, $variant, $quantity, $kind, $reference, $account);
            }
        });

        wholesaleOrderPlace()->assertSessionHasErrors(['stock' => __('orders.refused.stock_unavailable')]);

        expect(Order::query()->count())->toBe(0)
            ->and(DB::table('order_items')->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0)
            ->and(StockReservation::query()->count())->toBe(0)
            ->and($this->stock->refresh()->reserved)->toBe(0)
            ->and($this->stock->available)->toBe(50)
            ->and($teaStock->refresh()->reserved)->toBe(0);
    });

    it('is only for an account whose package includes wholesale', function () {
        $fingerprint = wholesaleOrderFingerprint();
        $this->package->features()->create(['feature' => PackageFeature::WholesaleEnabled->value, 'value' => '0']);

        $this->post(route('wholesale.orders.store'), ['fingerprint' => $fingerprint])->assertForbidden();

        expect(Order::query()->count())->toBe(0)
            ->and(StockReservation::query()->count())->toBe(0);
    });
});

describe('the intended resale channel (P4-14)', function () {
    it('records the channel for reporting and charges, reserves and allows exactly the same', function () {
        wholesaleOrderPlace(['intended_resale_channel' => 'marketplace'])->assertRedirect('https://pay.test/go');

        $order = Order::query()->sole();

        expect($order->intended_resale_channel)->toBe(IntendedResaleChannel::Marketplace)
            ->and($order->total_minor->minorUnits)->toBe(2000000)
            ->and($order->payment?->amount_minor->minorUnits)->toBe(2000000)
            ->and($this->stock->refresh()->reserved)->toBe(10);

        $this->get(route('wholesale.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('order.intended_resale_channel', 'marketplace'));
    });

    it('is optional', function () {
        wholesaleOrderPlace()->assertRedirect('https://pay.test/go');

        expect(Order::query()->sole()->intended_resale_channel)->toBeNull();
    });

    it('refuses a channel that is not one of the choices', function () {
        wholesaleOrderPlace(['intended_resale_channel' => 'black_market'])->assertSessionHasErrors('intended_resale_channel');

        expect(Order::query()->count())->toBe(0);
    });

    it('cannot be rewritten once the order is placed', function () {
        $order = wholesaleOrderPlaced();

        expect(fn () => DB::transaction(fn () => DB::table('orders')->whereKey($order->id)->update(['intended_resale_channel' => 'other'])))
            ->toThrow(QueryException::class);
    });
});

describe('paying (P4-9, P4-11)', function () {
    it('settles on the notification: commits the stock, pays the same order, issues its invoice and empties the cart', function () {
        $order = wholesaleOrderPlaced();

        wholesaleOrderNotify($order)->assertOk();

        $order->refresh();
        $payment = $order->payment;
        $invoice = Invoice::query()->where('payment_id', $payment?->id)->sole();

        expect($payment?->status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and($order->items()->sole()->stockReservation?->status)->toBe(StockReservationStatus::Committed)
            ->and($this->stock->refresh()->processing)->toBe(10)
            ->and($this->stock->reserved)->toBe(0)
            ->and($invoice->total_minor->minorUnits)->toBe(2000000)
            ->and($invoice->purpose)->toBe(PaymentPurpose::WholesaleOrder)
            ->and($invoice->lines->pluck('type')->all())->toBe(['wholesale_goods'])
            ->and($order->statusHistory->pluck('new_status')->all())->toBe([OrderStatus::PaymentPending, OrderStatus::Paid])
            ->and($order->statusHistory->last()?->source)->toBe(OrderStatusChangeSource::PaymentGateway)
            ->and(wholesaleOrderCartOf()->items()->count())->toBe(0)
            ->and(wholesaleOrderCartOf()->confirmed_at)->toBeNull();
    });

    it('answers every repeated callback without committing, invoicing or moving anything again', function () {
        $order = wholesaleOrderPlaced();

        wholesaleOrderNotify($order);
        wholesaleOrderNotify($order);
        $this->get(route('wholesale.orders.payment.return', ['order' => $order->public_id, ...wholesaleOrderIpn((string) $order->payment?->reference)]));
        app(ExpireUnpaidOrders::class)->handle();

        expect(Order::query()->count())->toBe(1)
            ->and(Payment::query()->count())->toBe(1)
            ->and(Invoice::query()->count())->toBe(1)
            ->and(StockReservation::query()->count())->toBe(1)
            ->and(wholesaleOrderMovements(StockMovementType::ReservationCommitted))->toBe(1)
            ->and($order->refresh()->statusHistory()->count())->toBe(2)
            ->and($this->stock->refresh()->processing)->toBe(10);
    });

    it('settles on the way back from the gateway and shows the order paid, with its invoice', function () {
        $order = wholesaleOrderPlaced();

        $this->get(route('wholesale.orders.payment.return', ['order' => $order->public_id, ...wholesaleOrderIpn((string) $order->payment?->reference)]))
            ->assertRedirect(route('wholesale.orders.show', $order->public_id));

        $invoice = Invoice::query()->sole();

        $this->get(route('wholesale.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.status', 'paid')
                ->where('order.payment_state', 'paid')
                ->where('order.stock.state', 'committed')
                ->where('order.invoice.number', $invoice->number)
                ->where('order.timeline.1.note', __('orders.notes.paid'))
                ->where('can.pay', false)
                ->where('can.cancel', false));

        // And from the invoice back to the order.
        $this->get(route('subscription.invoices.show', $invoice->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.order.id', $order->public_id)
                ->where('invoice.order.reference', $order->reference));
    });

    it('does not settle on a return the gateway will not confirm', function () {
        $order = wholesaleOrderPlaced();
        $this->validation['status'] = 'INVALID_TRANSACTION';

        $this->get(route('wholesale.orders.payment.return', ['order' => $order->public_id, ...wholesaleOrderIpn((string) $order->payment?->reference)]))
            ->assertRedirect(route('wholesale.orders.show', $order->public_id));

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment?->status)->toBe(PaymentStatus::Failed)
            ->and(Invoice::query()->count())->toBe(0)
            ->and($this->stock->refresh()->available)->toBe(50);
    });

    it('never pays for an order when the gateway reports a different amount', function () {
        $order = wholesaleOrderPlaced();
        $this->validation['currency_amount'] = '1.00';

        wholesaleOrderNotify($order);

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment?->status)->toBe(PaymentStatus::Failed)
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('keeps the order and offers payment again when the gateway cannot be reached', function () {
        $this->gateway['reachable'] = false;

        wholesaleOrderPlace()->assertSessionHasNoErrors();

        $order = Order::query()->sole();

        expect($order->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->payment?->status)->toBe(PaymentStatus::Draft)
            ->and($this->stock->refresh()->reserved)->toBe(10)
            ->and(PaymentLog::query()->where('event', 'initiate')->where('outcome', 'unavailable')->count())->toBe(1);

        $this->get(route('wholesale.orders.show', $order->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.pay', true)->where('order.payment.window_open', true));

        // Once it answers again, the same payment goes to the gateway.
        $this->gateway['reachable'] = true;

        $this->post(route('wholesale.orders.payment.store', $order->public_id))->assertRedirect('https://pay.test/go');

        expect(Payment::query()->count())->toBe(1)
            ->and($order->refresh()->payment?->status)->toBe(PaymentStatus::Initiated);
    });

    it('continues to payment for the same payment while the window is open', function () {
        $order = wholesaleOrderPlaced();

        $this->post(route('wholesale.orders.payment.store', $order->public_id))->assertRedirect('https://pay.test/go');

        expect(Payment::query()->count())->toBe(1);

        $this->travelTo($order->payment?->expires_at->addSecond());

        $this->post(route('wholesale.orders.payment.store', $order->public_id))
            ->assertSessionHasErrors(['order' => __('orders.refused.payment_window_closed')]);
    });
});

describe('failed, cancelled and expired payments (P4-10)', function () {
    it('keeps the order waiting and its stock held when the person comes back from a failed or cancelled payment', function () {
        // A failure or cancel address is only a claim, and one anybody can send a
        // signed-in buyer to. It cancels nothing; the buyer, the gateway's
        // confirmed answer or the payment window does.
        $order = wholesaleOrderPlaced();

        $this->get(route('wholesale.orders.payment.failed', $order->public_id))
            ->assertRedirect(route('wholesale.orders.show', $order->public_id));
        $this->get(route('wholesale.orders.payment.cancelled', $order->public_id))
            ->assertRedirect(route('wholesale.orders.show', $order->public_id));

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->payment?->status)->toBe(PaymentStatus::Initiated)
            ->and($this->stock->refresh()->reserved)->toBe(10)
            ->and(Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'validationserverAPI')))->toHaveCount(0);

        $this->get(route('wholesale.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.pay', true)->where('can.cancel', true));
    });

    it('cancels the order and gives its stock back when the buyer cancels it after backing out at the gateway', function () {
        $order = wholesaleOrderPlaced();

        $this->get(route('wholesale.orders.payment.cancelled', $order->public_id));
        $this->post(route('wholesale.orders.cancellation.store', $order->public_id))->assertSessionHasNoErrors();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment?->status)->toBe(PaymentStatus::Cancelled)
            ->and($order->items()->sole()->stockReservation?->status)->toBe(StockReservationStatus::Released)
            ->and($this->stock->refresh()->available)->toBe(50)
            // The lines stay for another try; the confirmation does not.
            ->and(wholesaleOrderCartOf()->items()->count())->toBe(1)
            ->and(wholesaleOrderCartOf()->confirmed_at)->toBeNull();
    });

    it('cancels the order when the gateway itself confirms a signed failure', function () {
        $order = wholesaleOrderPlaced();
        $this->validation['status'] = 'FAILED';

        $this->get(route('wholesale.orders.payment.failed', ['order' => $order->public_id, ...wholesaleOrderIpn((string) $order->payment?->reference)]));

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment?->status)->toBe(PaymentStatus::Failed)
            ->and($this->stock->refresh()->available)->toBe(50);
    });

    it('settles when the gateway posts the buyer back, with no session or form token, and writes no cookie', function () {
        $order = wholesaleOrderPlaced();
        $this->app['auth']->forgetGuards();

        $response = $this->post(route('wholesale.orders.payment.return', $order->public_id), wholesaleOrderIpn((string) $order->payment?->reference));

        $response->assertStatus(303)->assertRedirect(route('wholesale.orders.payment.return', $order->public_id));

        expect($response->headers->getCookies())->toBe([])
            ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and(Invoice::query()->count())->toBe(1);
    });

    it('ignores a posted return with a broken signature or naming another payment', function () {
        $order = wholesaleOrderPlaced();
        $this->app['auth']->forgetGuards();

        $forged = wholesaleOrderIpn((string) $order->payment?->reference);
        $forged['verify_sign'] = str_repeat('0', 32);

        $this->post(route('wholesale.orders.payment.return', $order->public_id), $forged)->assertStatus(303);

        // Somebody else's genuinely signed transaction, posted at this order's address.
        $theirs = Payment::create([
            'business_account_id' => $this->rahim->id,
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Initiated,
            'amount_minor' => 2000000,
            'currency_code' => 'BDT',
            'gateway' => 'sslcommerz',
        ]);

        $this->post(route('wholesale.orders.payment.return', $order->public_id), wholesaleOrderIpn($theirs->reference))->assertStatus(303);

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and($theirs->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and(Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'validationserverAPI')))->toHaveCount(0)
            ->and(PaymentLog::query()->where('event', 'return')->where('outcome', 'refused_signature')->count())->toBe(1);
    });

    it('gives a coupon\'s held use back with the cancelled order', function () {
        $coupon = Coupon::create([
            'code' => 'BULK500',
            'name' => 'Bulk buyer',
            'discount_type' => DiscountType::Fixed,
            'value' => 50000,
            'currency_code' => 'BDT',
            'applies_to' => CouponScope::WholesaleOrder,
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);
        $this->put(route('wholesale.checkout.coupon.apply'), ['code' => $coupon->code]);
        wholesaleOrderConfirm($this->karim);
        $this->validation['currency_amount'] = '19500.00';

        $order = wholesaleOrderPlaced();
        $this->post(route('wholesale.orders.cancellation.store', $order->public_id))->assertSessionHasNoErrors();

        expect(CouponRedemption::query()->where('payment_id', $order->payment_id)->sole()->status)->toBe(RedemptionStatus::Released);
    });

    it('never cancels a paid order on a forged failure redirect', function () {
        $order = wholesaleOrderPlaced();
        wholesaleOrderNotify($order);

        $this->get(route('wholesale.orders.payment.failed', $order->public_id));

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->payment?->status)->toBe(PaymentStatus::Paid)
            ->and($this->stock->refresh()->processing)->toBe(10);
    });

    it('expires the order when the window closes, giving the stock back exactly once', function () {
        $order = wholesaleOrderPlaced();

        $this->travelTo($order->payment?->expires_at->addSecond());

        $sweep = app(ExpireUnpaidOrders::class);

        expect($sweep->handle())->toBe(['confirmed' => 0, 'cancelled' => 0, 'expired' => 1])
            ->and($sweep->handle())->toBe(['confirmed' => 0, 'cancelled' => 0, 'expired' => 0]);

        // The reservation sweep afterwards finds nothing left to release.
        $this->travel(5)->minutes();
        app(ReleaseExpiredReservations::class)->handle();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment?->status)->toBe(PaymentStatus::Cancelled)
            ->and($order->statusHistory->last()?->source)->toBe(OrderStatusChangeSource::Scheduler)
            ->and(wholesaleOrderMovements(StockMovementType::ReservationReleased))->toBe(1)
            ->and(wholesaleOrderMovements(StockMovementType::ReservationExpired))->toBe(0)
            ->and($this->stock->refresh()->available)->toBe(50);
    });

    it('cancels without releasing twice when the reservation sweep got there first', function () {
        $order = wholesaleOrderPlaced();

        $this->travel(20)->minutes();
        app(ReleaseExpiredReservations::class)->handle();
        app(ExpireUnpaidOrders::class)->handle();

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and(wholesaleOrderMovements(StockMovementType::ReservationExpired))->toBe(1)
            ->and(wholesaleOrderMovements(StockMovementType::ReservationReleased))->toBe(0)
            ->and($this->stock->refresh()->available)->toBe(50)
            ->and($this->stock->reserved)->toBe(0);
    });

    it('sends a success that arrives after the window to reconciliation, never to a paid or held order', function () {
        $order = wholesaleOrderPlaced();

        // Past the payment window, still inside the reservation's own.
        $this->travelTo($order->payment?->expires_at->addSeconds(10));

        wholesaleOrderNotify($order);

        $order->refresh();

        expect($order->payment?->status)->toBe(PaymentStatus::ReconciliationRequired)
            ->and($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->statusHistory->pluck('new_status')->all())->toBe([OrderStatus::PaymentPending, OrderStatus::Cancelled])
            ->and($order->items()->sole()->stockReservation?->status)->toBe(StockReservationStatus::Released)
            ->and($this->stock->refresh()->processing)->toBe(0)
            ->and($this->stock->available)->toBe(50)
            ->and(Invoice::query()->count())->toBe(0);

        $this->get(route('wholesale.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('order.payment_state', 'reconciliation'));
    });

    it('sends a success that arrives after the sweep cancelled the order to reconciliation', function () {
        $order = wholesaleOrderPlaced();

        $this->travel(20)->minutes();
        app(ExpireUnpaidOrders::class)->handle();

        wholesaleOrderNotify($order);

        expect($order->refresh()->payment?->status)->toBe(PaymentStatus::ReconciliationRequired)
            ->and($order->status)->toBe(OrderStatus::Cancelled)
            ->and(Invoice::query()->count())->toBe(0)
            ->and($this->stock->refresh()->processing)->toBe(0);
    });
});

describe('a settled order whose stock is not there (P4-10)', function () {
    it('holds the order for review, records why for staff, and issues no invoice', function () {
        $order = wholesaleOrderPlaced();

        // Somebody released the stock while the payment was still open.
        app(StockReservations::class)->release($order->items()->sole()->stockReservation, 'Released by hand');

        wholesaleOrderNotify($order);

        $order->refresh();
        $hold = $order->statusHistory->last();

        expect($order->payment?->status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::OnHold)
            ->and($order->held_at)->not->toBeNull()
            ->and($order->hold_reason)->toContain('could not be committed')
            ->and($hold?->internal_note)->toContain('already released')
            ->and($hold?->public_note)->toBe('orders.notes.held')
            ->and(PaymentLog::query()->where('outcome', 'order_held')->count())->toBe(1)
            ->and(Invoice::query()->count())->toBe(0)
            ->and(wholesaleOrderCartOf()->items()->count())->toBe(0);

        // A repeated callback does not hold it twice.
        wholesaleOrderNotify($order);

        expect($order->refresh()->statusHistory()->count())->toBe(2)
            ->and(PaymentLog::query()->where('outcome', 'order_held')->count())->toBe(1);

        // The buyer reads the review state, never the staff reason.
        $page = $this->get(route('wholesale.orders.show', $order->public_id));

        $page->assertInertia(fn (Assert $page) => $page->where('order.status', 'on_hold'));
        expect(json_encode($page->viewData('page')))->not->toContain('already released');
    });

    it('finishes an order whose settlement recorded the money and stopped', function () {
        $order = wholesaleOrderPlaced();
        $payment = $order->payment;

        $payment?->transitionTo(PaymentStatus::Paid);
        $payment?->forceFill(['completed_at' => now()])->save();

        expect(app(ExpireUnpaidOrders::class)->handle()['confirmed'])->toBe(1)
            ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and(Invoice::query()->where('payment_id', $payment?->id)->count())->toBe(1);
    });
});

describe('the account\'s own actions', function () {
    it('lets the account cancel an order before paying, giving its stock back', function () {
        $order = wholesaleOrderPlaced();

        $this->get(route('wholesale.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.pay', true)->where('can.cancel', true));

        $this->post(route('wholesale.orders.cancellation.store', $order->public_id))
            ->assertRedirect(route('wholesale.orders.show', $order->public_id));

        $order->refresh();
        $change = $order->statusHistory->last();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($change?->source)->toBe(OrderStatusChangeSource::Account)
            ->and($change?->changed_by)->toBe($this->karim->owner_id)
            ->and($order->payment?->status)->toBe(PaymentStatus::Cancelled)
            ->and($this->stock->refresh()->available)->toBe(50);
    });

    it('refuses to cancel an order whose payment the gateway is still confirming', function () {
        $order = wholesaleOrderPlaced();
        $order->payment?->transitionTo(PaymentStatus::Pending)->save();

        $this->post(route('wholesale.orders.cancellation.store', $order->public_id))
            ->assertSessionHasErrors(['order' => __('orders.refused.payment_in_flight')]);

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and($this->stock->refresh()->reserved)->toBe(10);
    });

    it('refuses to cancel an order that has been paid', function () {
        $order = wholesaleOrderPlaced();
        wholesaleOrderNotify($order);

        $this->post(route('wholesale.orders.cancellation.store', $order->public_id))
            ->assertSessionHasErrors(['order' => __('orders.refused.not_awaiting_payment')]);

        expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    });

    it('lets nobody else see, pay for, cancel or return to the order', function () {
        $order = wholesaleOrderPlaced();

        $this->actingAs($this->rahim->owner);

        $this->get(route('wholesale.orders.show', $order->public_id))->assertNotFound();
        $this->post(route('wholesale.orders.payment.store', $order->public_id))->assertNotFound();
        $this->post(route('wholesale.orders.cancellation.store', $order->public_id))->assertNotFound();
        $this->get(route('wholesale.orders.payment.failed', $order->public_id))->assertNotFound();
        $this->get(route('wholesale.orders.payment.cancelled', $order->public_id))->assertNotFound();
        $this->get(route('wholesale.orders.payment.return', ['order' => $order->public_id, ...wholesaleOrderIpn((string) $order->payment?->reference)]))->assertNotFound();

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->payment?->status)->toBe(PaymentStatus::Initiated)
            ->and($this->stock->refresh()->reserved)->toBe(10);
    });

    it('shows the checkout the order waiting for payment instead of a second way to pay', function () {
        $order = wholesaleOrderPlaced();

        $this->get(route('wholesale.checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.pending_order.id', $order->public_id)
                ->where('checkout.pending_order.reference', $order->reference)
                ->where('checkout.resale_channels', IntendedResaleChannel::values()));
    });

    it('never lets the activation return close a wholesale order\'s payment', function () {
        $order = wholesaleOrderPlaced();

        $this->get(route('checkout.cancelled'));

        expect($order->refresh()->payment?->status)->toBe(PaymentStatus::Initiated)
            ->and($order->status)->toBe(OrderStatus::PaymentPending)
            ->and($this->stock->refresh()->reserved)->toBe(10);
    });
});

it('is swept by the scheduler every minute', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('Confirm or cancel wholesale orders waiting for payment');
});
