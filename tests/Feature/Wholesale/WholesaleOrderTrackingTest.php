<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Wholesale order tracking for the account that placed the order (P4-12, §10.2).
 *
 * An account follows its own wholesale orders and nobody else's: what it bought
 * and for how much, where it goes, where the payment and the stock stand, and a
 * timeline written for the buyer — never an internal note, a staff reason, who
 * on the platform made a change, a cost, a warehouse or an allocation.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->karim = testBusinessAccount(AccountStatus::Active);
    $this->rahim = testBusinessAccount(AccountStatus::Active);

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka central', 'is_default' => true]);
    $this->product = Product::create([
        'name' => 'Electric kettle',
        'sku' => 'FW-KT-17',
        'category_id' => Category::create(['name' => 'Kitchen'])->id,
        'base_cost' => Money::fromDecimal('1200.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2000.00', Currency::BDT),
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);
    $this->stock = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($this->stock, null, StockBucket::Available, 100, StockMovementType::Adjustment);
});

/**
 * An order with one line of ten kettles, holding its stock.
 *
 * @param  array<string, mixed>  $overrides
 */
function trackingOrder(BusinessAccount $account, array $overrides = [], int $lines = 1): Order
{
    $lineTotal = bcmul('20000.00', (string) $lines, 2);

    $order = Order::factory()->create([
        'business_account_id' => $account->id,
        'subtotal' => Money::fromDecimal($lineTotal, Currency::BDT),
        'total' => Money::fromDecimal($lineTotal, Currency::BDT),
        ...$overrides,
    ]);

    for ($line = 1; $line <= $lines; $line++) {
        $reservation = app(StockReservations::class)->reserve(
            test()->product, null, 10, ReservationKind::OnlinePayment, $order->reference.'-'.$line, $account,
        );

        DB::table('order_items')->insert([
            'public_id' => (string) Str::ulid(),
            'order_id' => $order->id,
            'line_number' => $line,
            'product_id' => test()->product->id,
            'sku' => 'FW-KT-17',
            'product_name' => 'Electric kettle',
            'quantity' => 10,
            'currency_code' => 'BDT',
            'unit_price' => '2000.00',
            'line_subtotal' => '20000.00',
            'line_total' => '20000.00',
            'stock_reservation_id' => $reservation->id,
            'created_at' => now(),
        ]);
    }

    return $order->refresh();
}

it('lists the account\'s own wholesale orders, newest first, and nobody else\'s', function () {
    $older = trackingOrder($this->karim, ['placed_at' => now()->subDay()]);
    $newer = trackingOrder($this->karim);
    $theirs = trackingOrder($this->rahim);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('wholesale/orders/index')
            ->has('orders.data', 2)
            ->where('orders.data.0.reference', $newer->reference)
            ->where('orders.data.1.reference', $older->reference)
            ->where('orders.data.0.status', 'payment_pending')
            ->where('orders.data.0.payment_state', 'awaiting')
            ->where('orders.data.0.item_count', 1)
            ->where('orders.data.0.total.amount', '20000.00'));

    expect(json_encode($this->get(route('wholesale.orders.index'))->viewData('page')))->not->toContain($theirs->reference);
});

it('shows an order as it was recorded: lines, totals, addresses, payment, stock and timeline', function () {
    $order = trackingOrder($this->karim);
    $order->payment?->forceFill(['gateway' => 'sslcommerz', 'expires_at' => now()->addMinutes(15)])->save();
    $order->recordPlacement(new StatusChange(actorId: $this->karim->owner_id, publicNote: 'Waiting for your payment.'), OrderStatusChangeSource::Checkout);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.show', $order->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('wholesale/orders/show')
            ->where('order.reference', $order->reference)
            ->where('order.lines.0.name', 'Electric kettle')
            ->where('order.lines.0.sku', 'FW-KT-17')
            ->where('order.lines.0.quantity', 10)
            ->where('order.lines.0.total.amount', '20000.00')
            ->where('order.totals.total.amount', '20000.00')
            ->where('order.addresses.shipping.city', 'Dhaka')
            ->where('order.payment.gateway', 'SSLCommerz')
            ->where('order.payment_state', 'awaiting')
            ->where('order.stock.state', 'held')
            ->whereNot('order.stock.held_until', null)
            ->has('order.timeline', 1)
            ->where('order.timeline.0.status', 'payment_pending')
            ->where('order.timeline.0.note', 'Waiting for your payment.')
            ->where('order.invoice', null));
});

it('never sends an internal note, a staff reason, who changed it, a hold reason, a cost, a warehouse or an allocation', function () {
    $order = trackingOrder($this->karim);
    $order->recordPlacement(new StatusChange(
        actorId: $this->karim->owner_id,
        reason: 'STAFF-REASON-TEXT',
        internalNote: 'INTERNAL-NOTE-TEXT',
        publicNote: 'Waiting for your payment.',
    ), OrderStatusChangeSource::Checkout);

    $order->forceFill(['held_at' => now(), 'hold_reason' => 'HOLD-REASON-TEXT']);
    $order->moveTo(OrderStatus::OnHold, StatusChange::bySystem('HOLD-REASON-TEXT'), OrderStatusChangeSource::PaymentGateway);

    $response = $this->actingAs($this->karim->owner)->get(route('wholesale.orders.show', $order->public_id))->assertOk();
    $props = json_encode($response->viewData('page')['props']['order']);

    foreach (['STAFF-REASON-TEXT', 'INTERNAL-NOTE-TEXT', 'HOLD-REASON-TEXT', 'base_cost', '1200.00', 'warehouse', 'allocation', 'changed_by'] as $secret) {
        expect($props)->not->toContain($secret);
    }
});

it('says where the payment stands, read from the payment every time', function (PaymentStatus $status, string $state) {
    $order = trackingOrder($this->karim);
    DB::table('payments')->where('id', $order->payment_id)->update(['status' => $status->value]);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.show', $order->public_id))
        ->assertInertia(fn (Assert $page) => $page->where('order.payment_state', $state));
})->with([
    'not yet sent to a gateway' => [PaymentStatus::Draft, 'awaiting'],
    'sent to a gateway' => [PaymentStatus::Initiated, 'awaiting'],
    'pending at the gateway' => [PaymentStatus::Pending, 'confirming'],
    'paid' => [PaymentStatus::Paid, 'paid'],
    'failed' => [PaymentStatus::Failed, 'failed'],
    'cancelled' => [PaymentStatus::Cancelled, 'cancelled'],
    'arrived after the order closed' => [PaymentStatus::ReconciliationRequired, 'reconciliation'],
]);

it('says where the stock stands across every line', function (Closure $arrange, string $state) {
    $order = trackingOrder($this->karim, [], lines: 2);
    $arrange($order);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.show', $order->public_id))
        ->assertInertia(fn (Assert $page) => $page->where('order.stock.state', $state));
})->with([
    'all held' => [fn () => null, 'held'],
    'all committed' => [function (Order $order) {
        foreach ($order->items as $item) {
            app(StockReservations::class)->commit($item->stockReservation);
        }
    }, 'committed'],
    'all given back' => [function (Order $order) {
        foreach ($order->items as $item) {
            app(StockReservations::class)->release($item->stockReservation);
        }
    }, 'released'],
    'lines that disagree' => [function (Order $order) {
        app(StockReservations::class)->commit($order->items[0]->stockReservation);
        app(StockReservations::class)->release($order->items[1]->stockReservation);
    }, 'attention'],
]);

it('links the invoice once one has been issued', function () {
    $order = trackingOrder($this->karim);
    $invoice = app(IssueInvoice::class)->handle($order->payment);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.show', $order->public_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.invoice.id', $invoice->public_id)
            ->where('order.invoice.number', $invoice->number));
});

it('finds no order of another account, whatever identifier is typed', function () {
    $theirs = trackingOrder($this->rahim);

    $this->actingAs($this->karim->owner)->get(route('wholesale.orders.show', $theirs->public_id))->assertNotFound();
    $this->actingAs($this->karim->owner)->get(route('wholesale.orders.show', $theirs->reference))->assertNotFound();
    $this->actingAs($this->karim->owner)->get(route('wholesale.orders.show', (string) $theirs->id))->assertNotFound();
});

it('lets every member of the account follow its orders', function () {
    $order = trackingOrder($this->karim);
    $member = User::factory()->staffOf($this->karim, AccountRole::Staff)->create();

    $this->actingAs($member)->get(route('wholesale.orders.show', $order->public_id))->assertOk();
});

it('turns platform staff away, since they have no business account', function () {
    $order = trackingOrder($this->karim);
    $staff = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($staff)->get(route('wholesale.orders.index'))->assertRedirect();
    $this->actingAs($staff)->get(route('wholesale.orders.show', $order->public_id))->assertRedirect();
});

it('opens the orders door once the account has an order, and keeps the orders readable without wholesale', function () {
    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.index'))
        ->assertInertia(fn (Assert $page) => $page->where('account.hasWholesaleOrders', false)->has('orders.data', 0));

    trackingOrder($this->karim);

    // The account's package includes no wholesale at all, and the order is still theirs to follow.
    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.allowsWholesale', false)
            ->where('account.hasWholesaleOrders', true)
            ->has('orders.data', 1));
});
