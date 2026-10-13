<?php

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\OrderNotificationStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderStatusChange;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\RefreshWebsiteBestSellers;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Best sellers (P2 C3, D31 H5): the last 30 days of paid online and confirmed
 * cash-on-delivery sales, net of received returns, ranked by units, then
 * orders, then selection.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->website);
});

function bestSellerSelection(string $name, ?Website $website = null): WebsiteProduct
{
    $website ??= test()->website;

    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct(['name' => $name])->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);
}

/**
 * A website order for the given lines, as an online order that was paid or a
 * cash-on-delivery order the customer confirmed — or, with `$settled` false,
 * one that never was.
 *
 * @param  list<array{0: WebsiteProduct, 1: int}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function bestSellerOrder(array $lines, string $method = 'online', bool $settled = true, array $attributes = [], ?Website $website = null): Order
{
    $website ??= test()->website;

    $payment = Payment::create([
        'business_account_id' => $website->business_account_id,
        'purpose' => PaymentPurpose::WebsiteOrder,
        'status' => $method === 'online' && $settled ? PaymentStatus::Paid : PaymentStatus::Pending,
        'gateway' => $method === 'online' ? 'sslcommerz' : null,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('1000.00'),
    ]);

    $buyer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Ayesha Rahman',
    ]);

    $order = Order::factory()->create([
        'source' => OrderSource::Website,
        'website_id' => $website->id,
        'website_customer_id' => $buyer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(10)),
        'placed_by' => null,
        'cart_id' => null,
        'business_account_id' => $website->business_account_id,
        'payment_id' => $payment->id,
        'status' => match (true) {
            $method === 'online' && $settled => OrderStatus::Paid,
            $method === 'online' => OrderStatus::PaymentPending,
            default => $settled ? OrderStatus::Confirmed : OrderStatus::CustomerVerificationPending,
        },
        'paid_at' => $method === 'online' && $settled ? now() : null,
        'placed_at' => now()->subDays(2),
        ...(($attributes['status'] ?? null) === OrderStatus::Cancelled ? ['cancelled_at' => now()] : []),
        ...$attributes,
    ]);

    foreach (array_values($lines) as $index => [$selection, $quantity]) {
        OrderItem::create([
            'order_id' => $order->id,
            'line_number' => $index + 1,
            'product_id' => $selection->product_id,
            'sku' => $selection->product->sku,
            'product_name' => $selection->product->name,
            'quantity' => $quantity,
            'currency_code' => 'BDT',
            'unit_price' => Money::fromDecimal('100.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal(bcmul('100.00', (string) $quantity, 2), Currency::BDT),
            'line_total' => Money::fromDecimal(bcmul('100.00', (string) $quantity, 2), Currency::BDT),
            'created_at' => now(),
        ]);
    }

    if ($method === 'cod' && $settled) {
        bestSellerHistory($order, OrderStatusChangeSource::Storefront);
    }

    return $order;
}

/**
 * The move to `confirmed`, as the history records it.
 */
function bestSellerHistory(Order $order, OrderStatusChangeSource $source): void
{
    OrderStatusChange::create([
        'order_id' => $order->id,
        'previous_status' => OrderStatus::CustomerVerificationPending,
        'new_status' => OrderStatus::Confirmed,
        'changed_at' => now(),
        'source' => $source,
        'reason' => 'Confirmed.',
        'notification_status' => OrderNotificationStatus::NotRequired,
    ]);
}

/**
 * A return of `$quantity` units of the order's first line, at `$status`, with
 * what arrived recorded when it was received.
 */
function bestSellerReturn(Order $order, int $quantity, ReturnStatus $status): OrderReturn
{
    $return = OrderReturn::create([
        'order_id' => $order->id,
        'business_account_id' => $order->business_account_id,
        'website_id' => $order->website_id,
        'source' => OrderStatusChangeSource::Staff,
        'status' => ReturnStatus::Requested,
        'reason' => ReturnReason::Other,
        'requested_at' => now(),
    ]);

    $return->items()->create([
        'order_item_id' => $order->items()->orderBy('line_number')->firstOrFail()->id,
        'quantity' => $quantity,
        'approved_quantity' => $quantity,
        'received_quantity' => in_array($status, RefreshWebsiteBestSellers::COUNTED_RETURNS, true) ? $quantity : 0,
    ]);

    // Straight to the state under test, with what the database requires of it:
    // the returns workflow has its own tests.
    DB::table('order_returns')->where('id', $return->id)->update([
        'status' => $status->value,
        'decided_at' => $status === ReturnStatus::Requested ? null : now(),
        'decision_note' => $status === ReturnStatus::Requested ? null : 'Decided.',
        'received_at' => in_array($status, RefreshWebsiteBestSellers::COUNTED_RETURNS, true) ? now() : null,
        'refunded_at' => $status === ReturnStatus::Refunded ? now() : null,
        'cancelled_at' => $status === ReturnStatus::Cancelled ? now() : null,
    ]);

    return $return->refresh();
}

/**
 * The storefront's best-selling order, every page walked.
 *
 * @return list<string>
 */
function bestSellerRanking(): array
{
    app(RefreshWebsiteBestSellers::class)->handle();

    $names = [];
    $cursor = null;

    do {
        $page = storefrontCall(test()->credential, test()->secret, 'products', [
            'sort' => 'best_selling',
            'limit' => 2,
            ...($cursor === null ? [] : ['cursor' => $cursor]),
        ])->assertOk();

        $names = [...$names, ...array_column($page->json('data'), 'name')];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null && count($names) < 50);

    return $names;
}

/**
 * @return array<string, array{net_units: int, order_count: int}>
 */
function bestSellerSummary(): array
{
    return DB::table('website_product_sales')
        ->join('website_products', 'website_products.id', '=', 'website_product_sales.website_product_id')
        ->join('products', 'products.id', '=', 'website_products.product_id')
        ->orderBy('products.name')
        ->get(['products.name', 'net_units', 'order_count'])
        ->mapWithKeys(fn (object $row) => [$row->name => ['net_units' => (int) $row->net_units, 'order_count' => (int) $row->order_count]])
        ->all();
}

describe('ranking', function () {
    it('ranks by net units, then orders, then selection, with unsold selections last in selection order', function () {
        $apple = bestSellerSelection('Apple');
        $banana = bestSellerSelection('Banana');
        $cherry = bestSellerSelection('Cherry');
        bestSellerSelection('Damson');
        bestSellerSelection('Elder');

        // Banana: 5 units in one order. Cherry: 5 units over two orders. Apple: 2.
        bestSellerOrder([[$banana, 5]]);
        bestSellerOrder([[$cherry, 3]]);
        bestSellerOrder([[$cherry, 2]], 'cod');
        bestSellerOrder([[$apple, 2]]);

        expect(bestSellerRanking())->toBe(['Cherry', 'Banana', 'Apple', 'Damson', 'Elder']);
    });

    it('breaks a complete tie on the selection', function () {
        $first = bestSellerSelection('Zebra');
        $second = bestSellerSelection('Aardvark');

        bestSellerOrder([[$second, 1]]);
        bestSellerOrder([[$first, 1]]);

        expect(bestSellerRanking())->toBe(['Zebra', 'Aardvark']);
    });
});

describe('what counts as a sale', function () {
    it('counts paid online orders and cash-on-delivery orders the customer confirmed', function () {
        $online = bestSellerSelection('Online');
        $cod = bestSellerSelection('Cod');

        bestSellerOrder([[$online, 2]]);
        bestSellerOrder([[$cod, 3]], 'cod');

        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary())->toBe([
            'Cod' => ['net_units' => 3, 'order_count' => 1],
            'Online' => ['net_units' => 2, 'order_count' => 1],
        ]);
    });

    it('never counts an unpaid order, or cash on delivery the customer did not confirm', function () {
        $unpaid = bestSellerSelection('Unpaid');
        $unconfirmed = bestSellerSelection('Unconfirmed');
        $statusOnly = bestSellerSelection('Status only');
        $byStaff = bestSellerSelection('By staff');

        bestSellerOrder([[$unpaid, 4]], 'online', settled: false);
        bestSellerOrder([[$unconfirmed, 4]], 'cod', settled: false);

        // Confirmed by status alone, with no confirmation in its history.
        bestSellerOrder([[$statusOnly, 4]], 'cod', settled: false, attributes: ['status' => OrderStatus::Confirmed]);

        // A move to confirmed that the customer's code did not make.
        $staff = bestSellerOrder([[$byStaff, 4]], 'cod', settled: false, attributes: ['status' => OrderStatus::Confirmed]);
        bestSellerHistory($staff, OrderStatusChangeSource::Staff);

        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary())->toBe([]);
    });

    it('drops a sale that was later undone', function (OrderStatus $status) {
        $selection = bestSellerSelection('Undone');

        bestSellerOrder([[$selection, 4]], attributes: ['status' => $status]);

        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary())->toBe([]);
    })->with([
        'cancelled' => OrderStatus::Cancelled,
        'refund pending' => OrderStatus::RefundPending,
        'partially refunded' => OrderStatus::PartiallyRefunded,
        'refunded' => OrderStatus::Refunded,
    ]);

    it('keeps a sale that moved on to fulfilment', function () {
        $selection = bestSellerSelection('Shipped');

        bestSellerOrder([[$selection, 4]], attributes: ['status' => OrderStatus::Shipped]);

        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary())->toBe(['Shipped' => ['net_units' => 4, 'order_count' => 1]]);
    });

    it('looks back 30 days from placement, and no further', function () {
        $recent = bestSellerSelection('Recent');
        $old = bestSellerSelection('Old');

        bestSellerOrder([[$recent, 1]], attributes: ['placed_at' => now()->subDays(29)]);
        bestSellerOrder([[$old, 9]], attributes: ['placed_at' => now()->subDays(31)]);

        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary())->toBe(['Recent' => ['net_units' => 1, 'order_count' => 1]]);
    });
});

describe('returns', function () {
    it('takes away what came back, only once it was received', function (ReturnStatus $status, int $expected) {
        $selection = bestSellerSelection('Returned');
        $order = bestSellerOrder([[$selection, 5]]);

        bestSellerReturn($order, 2, $status);

        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary()['Returned']['net_units'])->toBe($expected);
    })->with([
        'received' => [ReturnStatus::Received, 3],
        'refunded' => [ReturnStatus::Refunded, 3],
        'only requested' => [ReturnStatus::Requested, 5],
        'approved, not yet back' => [ReturnStatus::Approved, 5],
        'rejected' => [ReturnStatus::Rejected, 5],
        'cancelled' => [ReturnStatus::Cancelled, 5],
    ]);

    it('takes each return away once, however many there are, without counting the order twice', function () {
        $selection = bestSellerSelection('Twice');
        $order = bestSellerOrder([[$selection, 6]]);

        bestSellerReturn($order, 1, ReturnStatus::Received);
        bestSellerReturn($order, 2, ReturnStatus::Refunded);

        app(RefreshWebsiteBestSellers::class)->handle();
        app(RefreshWebsiteBestSellers::class)->handle();

        expect(bestSellerSummary())->toBe(['Twice' => ['net_units' => 3, 'order_count' => 1]]);
    });

    it('never goes below nothing, and an order given back entirely is not a sale', function () {
        $returned = bestSellerSelection('All back');
        $kept = bestSellerSelection('Kept');

        $order = bestSellerOrder([[$returned, 2]]);
        bestSellerReturn($order, 2, ReturnStatus::Received);
        bestSellerOrder([[$kept, 1]]);

        expect(bestSellerRanking())->toBe(['Kept', 'All back'])
            ->and(bestSellerSummary())->toBe(['Kept' => ['net_units' => 1, 'order_count' => 1]]);
    });
});

describe('isolation and freshness', function () {
    it('counts only this website\'s own sales', function () {
        $mine = bestSellerSelection('Mine');
        $quiet = bestSellerSelection('Quiet');

        $other = Website::factory()->forAccount(websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']))->active()->create();
        $theirs = bestSellerSelection('Theirs', $other);

        // The same product sold heavily elsewhere.
        $sameProductElsewhere = WebsiteProduct::create([
            'website_id' => $other->id,
            'business_account_id' => $other->business_account_id,
            'product_id' => $quiet->product_id,
            'status' => WebsiteProductStatus::Published,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price' => Money::fromDecimal('2600.00', Currency::BDT),
            'published_at' => now(),
        ]);

        bestSellerOrder([[$theirs, 50], [$sameProductElsewhere, 40]], website: $other);
        bestSellerOrder([[$mine, 1]]);

        expect(bestSellerRanking())->toBe(['Mine', 'Quiet']);
    });

    it('never lists a best seller this website no longer has on sale', function () {
        $listed = bestSellerSelection('Listed');
        $withdrawn = bestSellerSelection('Withdrawn');

        bestSellerOrder([[$withdrawn, 9]]);
        bestSellerOrder([[$listed, 1]]);

        $withdrawn->forceFill(['status' => WebsiteProductStatus::Unpublished, 'unpublished_at' => now()])->save();

        expect(bestSellerRanking())->toBe(['Listed']);
    });

    it('gives the same answer every time it is refreshed, and follows a sale that is undone', function () {
        $selection = bestSellerSelection('Steady');
        $order = bestSellerOrder([[$selection, 3]]);

        $refresh = app(RefreshWebsiteBestSellers::class);

        expect($refresh->handle())->toBe(1)
            ->and($refresh->handle())->toBe(1)
            ->and(bestSellerSummary())->toBe(['Steady' => ['net_units' => 3, 'order_count' => 1]]);

        // Paid, then refunded: the move the database allows from paid.
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::RefundPending->value]);

        expect($refresh->handle())->toBe(0)
            ->and(bestSellerSummary())->toBe([]);
    });
});
