<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AdvanceOrderCourierStatus;
use App\Domain\Order\Actions\AdvanceStoreOrder;
use App\Domain\Order\Actions\CancelStoreOrder;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\MarkPartnerStockSold;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\StoreOrderAction;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The Store Order workflow (D34): one named action per step, in one fixed order;
 * stock held until dispatch and consumed there once; cancellation and returns
 * with their own stock rules; and every Partner kept to their own orders.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    Http::fake(fn () => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']));

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Alpha shop']);
    $this->selection = storeOrderSelection($this->website);
    $this->stock = websiteTestOwnsSelection($this->selection, 20);

    $this->staff = testPlatformStaff(PlatformRole::OrderManager);
    $this->staff->assignRole(PlatformRole::InventoryManager->value);
});

function storeOrderSelection(Website $website): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);
}

/**
 * A Store Order for two units, placed the way the storefront places one.
 */
function storeOrderPlace(?Website $website = null, string $method = 'cod', ?WebsiteProduct $selection = null, bool $codeNeeded = true): Order
{
    $website ??= test()->website;
    $selection ??= test()->selection;
    $website->forceFill(['sms_verification_enabled' => $codeNeeded])->save();

    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
        reference: 'SF-WF-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => $selection->product->sku, 'quantity' => 2]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: [
            'subtotal' => $money('5200.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('0.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('5200.00'),
        ],
        paymentMethod: $method,
    ));

    return $order->refresh();
}

/**
 * Walk a confirmed order up to (not through) a step, by the real actions.
 *
 * @param  list<StoreOrderAction>  $actions
 */
function storeOrderAdvance(Order $order, array $actions): Order
{
    foreach ($actions as $action) {
        app(AdvanceStoreOrder::class)->handle($order, $action, test()->staff);
    }

    return $order->refresh();
}

function storeOrderBuckets(): array
{
    return PartnerStockItem::query()->findOrFail(test()->stock->id)->buckets();
}

function storeOrderReservationStatus(Order $order): StockReservationStatus
{
    return $order->items()->sole()->stockReservation->refresh()->status;
}

function storeOrderHistoryCount(Order $order, OrderStatus $to): int
{
    return DB::table('order_status_history')->where('order_id', $order->id)->where('new_status', $to->value)->count();
}

const STORE_ORDER_FULL_RUN = [
    StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked, StoreOrderAction::Dispatch,
];

describe('stock and the moment it is consumed', function () {
    it('keeps the stock held when a customer confirms a cash-on-delivery order', function () {
        $order = storeOrderPlace();
        expect($order->status)->toBe(OrderStatus::CustomerVerificationPending);

        app(ConfirmCodOrder::class)->withoutCode($order);

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
            ->and(storeOrderReservationStatus($order))->toBe(StockReservationStatus::Active)
            ->and(storeOrderBuckets())->toMatchArray(['available' => 18, 'reserved' => 2, 'processing' => 0]);
    });

    it('keeps the stock held when an online payment settles', function () {
        $order = storeOrderPlace(method: 'online');
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        $payment->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'wf-'.Str::random(8)])->save();

        app(ConfirmOrderPayment::class)->handle($payment->refresh());

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and(storeOrderReservationStatus($order))->toBe(StockReservationStatus::Active)
            ->and(storeOrderBuckets())->toMatchArray(['reserved' => 2, 'processing' => 0, 'sold' => 0]);
    });

    it('holds the reservation through every step before dispatch, then consumes it once at dispatch', function () {
        $order = storeOrderPlace(codeNeeded: false);

        foreach ([StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked] as $action) {
            storeOrderAdvance($order, [$action]);

            expect(storeOrderReservationStatus($order))->toBe(StockReservationStatus::Active)
                ->and(storeOrderBuckets())->toMatchArray(['reserved' => 2, 'processing' => 0]);
        }

        storeOrderAdvance($order, [StoreOrderAction::Dispatch]);

        expect($order->status)->toBe(OrderStatus::Shipped)
            ->and(storeOrderReservationStatus($order))->toBe(StockReservationStatus::Committed)
            ->and(storeOrderBuckets())->toMatchArray(['available' => 18, 'reserved' => 0, 'processing' => 2]);
    });

    it('writes one history row per step', function () {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), STORE_ORDER_FULL_RUN);

        foreach ([OrderStatus::Processing, OrderStatus::Packing, OrderStatus::ReadyForPickup, OrderStatus::Shipped] as $status) {
            expect(storeOrderHistoryCount($order, $status))->toBe(1);
        }
    });

    it('does not deduct again when dispatch is asked twice', function () {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), STORE_ORDER_FULL_RUN);

        [, $moved] = app(AdvanceStoreOrder::class)->handle($order, StoreOrderAction::Dispatch, $this->staff);

        expect($moved)->toBeFalse()
            ->and(storeOrderBuckets())->toMatchArray(['reserved' => 0, 'processing' => 2])
            ->and(storeOrderHistoryCount($order, OrderStatus::Shipped))->toBe(1)
            ->and(DB::table('partner_stock_movements')->where('type', 'reservation_committed')->count())->toBe(1);
    });

    it('moves the committed units to sold on delivery without taking any more', function () {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), STORE_ORDER_FULL_RUN);

        app(MarkPartnerStockSold::class)->handle($order);
        app(MarkPartnerStockSold::class)->handle($order);

        expect(storeOrderBuckets())->toMatchArray(['available' => 18, 'processing' => 0, 'sold' => 2]);
    });
});

describe('the sequence', function () {
    it('refuses every step that skips ahead or goes back', function (array $walk, StoreOrderAction $attempt) {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), $walk);
        $before = $order->status;

        expect(fn () => app(AdvanceStoreOrder::class)->handle($order, $attempt, $this->staff))
            ->toThrow(StoreOrderRefused::class);

        expect($order->refresh()->status)->toBe($before);
    })->with([
        'confirmed → packing' => [[], StoreOrderAction::StartPacking],
        'confirmed → packed' => [[], StoreOrderAction::MarkPacked],
        'confirmed → dispatch' => [[], StoreOrderAction::Dispatch],
        'processing → packed' => [[StoreOrderAction::StartProcessing], StoreOrderAction::MarkPacked],
        'processing → dispatch' => [[StoreOrderAction::StartProcessing], StoreOrderAction::Dispatch],
        'packing → dispatch' => [[StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking], StoreOrderAction::Dispatch],
        'packed → processing (back)' => [[StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked], StoreOrderAction::StartProcessing],
    ]);

    it('does not let a pending order skip confirmation', function () {
        $order = storeOrderPlace();

        expect(fn () => app(AdvanceStoreOrder::class)->handle($order, StoreOrderAction::StartProcessing, $this->staff))
            ->toThrow(StoreOrderRefused::class);

        [, $moved] = app(AdvanceStoreOrder::class)->handle($order, StoreOrderAction::Confirm, $this->staff);

        expect($moved)->toBeTrue()->and($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('does not let staff confirm an online order that has not been paid', function () {
        $order = storeOrderPlace(method: 'online');

        expect(fn () => app(AdvanceStoreOrder::class)->handle($order, StoreOrderAction::Confirm, $this->staff))
            ->toThrow(StoreOrderRefused::class);
    });

    it('does not let the courier start before dispatch, and lets it after', function () {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), [StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked]);

        expect(fn () => app(AdvanceOrderCourierStatus::class)->handle($order, OrderCourierStatus::Assigned, $this->staff))
            ->toThrow(InvalidArgumentException::class);

        storeOrderAdvance($order, [StoreOrderAction::Dispatch]);

        app(AdvanceOrderCourierStatus::class)->handle($order->refresh(), OrderCourierStatus::Assigned, $this->staff);

        expect($order->refresh()->courier_status)->toBe(OrderCourierStatus::Assigned);
    });
});

describe('cancellation', function () {
    it('releases the reservation once, whichever step it is cancelled from', function (array $walk) {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), $walk);

        [, $first] = app(CancelStoreOrder::class)->handle($order, $this->staff, 'Customer asked.');
        [, $again] = app(CancelStoreOrder::class)->handle($order, $this->staff, 'Customer asked.');

        expect($first)->toBeTrue()->and($again)->toBeFalse()
            ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancellation_reason)->toBe('Customer asked.')
            ->and(storeOrderReservationStatus($order))->toBe(StockReservationStatus::Released)
            ->and(storeOrderBuckets())->toMatchArray(['available' => 20, 'reserved' => 0, 'processing' => 0])
            ->and(storeOrderHistoryCount($order, OrderStatus::Cancelled))->toBe(1)
            ->and(DB::table('partner_stock_movements')->where('type', 'reservation_released')->count())->toBe(1);
    })->with([
        'confirmed' => [[]],
        'processing' => [[StoreOrderAction::StartProcessing]],
        'packing' => [[StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking]],
        'packed' => [[StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked]],
    ]);

    it('needs a reason', function () {
        expect(fn () => app(CancelStoreOrder::class)->handle(storeOrderPlace(), $this->staff, '  '))
            ->toThrow(StoreOrderRefused::class);
    });

    it('sends a paid order to refund pending rather than refunding anything itself', function () {
        $order = storeOrderPlace(method: 'online');
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        $payment->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'wf-'.Str::random(8)])->save();
        app(ConfirmOrderPayment::class)->handle($payment->refresh());

        app(CancelStoreOrder::class)->handle($order->refresh(), $this->staff, 'Out of the colour.');

        expect($order->refresh()->status)->toBe(OrderStatus::RefundPending)
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(storeOrderBuckets())->toMatchArray(['available' => 20, 'reserved' => 0]);
    });

    it('refuses to cancel a dispatched order and leaves its stock alone', function () {
        $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), STORE_ORDER_FULL_RUN);

        expect(fn () => app(CancelStoreOrder::class)->handle($order, $this->staff, 'Changed mind.'))
            ->toThrow(StoreOrderRefused::class);

        expect(storeOrderBuckets())->toMatchArray(['reserved' => 0, 'processing' => 2, 'available' => 18]);
    });

    it('lets a partner cancel only while the order is pending', function () {
        $pending = storeOrderPlace();
        $confirmed = storeOrderAdvance(storeOrderPlace(codeNeeded: false), []);

        $this->actingAs($this->account->owner)
            ->post(route('store-orders.cancel', $confirmed->public_id), ['reason' => 'Nope'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->account->owner)
            ->post(route('store-orders.cancel', $pending->public_id), ['reason' => 'Customer asked'])
            ->assertSessionHasNoErrors();

        expect($pending->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($confirmed->refresh()->status)->toBe(OrderStatus::Confirmed);
    });
});

describe('returns after dispatch', function () {
    beforeEach(function () {
        $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

        $this->deliveredOrder = function (): OrderReturn {
            $order = storeOrderAdvance(storeOrderPlace(codeNeeded: false), STORE_ORDER_FULL_RUN);
            $order->moveTo(OrderStatus::Delivered, new StatusChange(reason: 'Delivered.'), OrderStatusChangeSource::System);
            app(MarkPartnerStockSold::class)->handle($order);

            [$return] = app(RequestOrderReturn::class)->handle($order->refresh(), new ReturnSubmission(
                reason: ReturnReason::NotAsDescribed,
                lines: [['sku' => $this->selection->product->sku, 'quantity' => 2]],
            ), OrderStatusChangeSource::Storefront);
            app(DecideOrderReturn::class)->approve($this->staff, $return, [], 'As described by the customer.');

            return $return->refresh();
        };
    });

    it('restores sellable stock only once the goods are received and accepted', function () {
        $return = ($this->deliveredOrder)();

        expect(storeOrderBuckets())->toMatchArray(['available' => 18, 'sold' => 2]);

        app(ReceiveReturnedItems::class)->handle($this->staff, $return, [
            new ReturnedLine($return->items()->sole()->public_id, 2, ReturnDisposition::Restock),
        ], $this->warehouse);

        expect(storeOrderBuckets())->toMatchArray(['available' => 20, 'sold' => 0]);
    });

    it('does not make damaged or quarantined returns sellable', function (ReturnDisposition $disposition, string $bucket) {
        $return = ($this->deliveredOrder)();

        app(ReceiveReturnedItems::class)->handle($this->staff, $return, [
            new ReturnedLine($return->items()->sole()->public_id, 2, $disposition),
        ], $this->warehouse);

        expect(storeOrderBuckets())->toMatchArray(['available' => 18, $bucket => 2]);
    })->with([
        'damaged' => [ReturnDisposition::Damaged, 'damaged'],
        'quarantined' => [ReturnDisposition::Quarantine, 'returned'],
    ]);
});

describe('who sees and who moves', function () {
    it('keeps a partner to their own store orders', function () {
        $mine = storeOrderPlace(codeNeeded: false);

        $other = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
        $otherSite = Website::factory()->forAccount($other)->active()->create(['name' => 'Beta shop']);
        $otherSelection = storeOrderSelection($otherSite);
        websiteTestOwnsSelection($otherSelection, 10);
        $theirs = storeOrderPlace($otherSite, selection: $otherSelection, codeNeeded: false);

        $this->actingAs($this->account->owner)->get(route('store-orders.index'))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', $mine->reference));

        // Not by URL, not by a filter, not by a cancellation.
        $this->actingAs($this->account->owner)->get(route('store-orders.show', $theirs->public_id))->assertNotFound();
        $this->actingAs($this->account->owner)->get(route('store-orders.index', ['storefront' => $otherSite->public_id]))
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));
        $this->actingAs($this->account->owner)
            ->post(route('store-orders.cancel', $theirs->public_id), ['reason' => 'x'])->assertNotFound();

        expect($theirs->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('never lets a partner advance a status, by the admin route or otherwise', function () {
        $order = storeOrderPlace(codeNeeded: false);

        $this->actingAs($this->account->owner)
            ->post(route('admin.store-orders.advance', [$order->public_id, 'start_processing']))
            ->assertForbidden();

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('shows staff every partner and storefront, filtered', function () {
        storeOrderPlace(codeNeeded: false);
        $other = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
        $otherSite = Website::factory()->forAccount($other)->active()->create(['name' => 'Beta shop']);
        $otherSelection = storeOrderSelection($otherSite);
        websiteTestOwnsSelection($otherSelection, 10);
        $beta = storeOrderPlace($otherSite, selection: $otherSelection, codeNeeded: false);
        storeOrderAdvance($beta, [StoreOrderAction::StartProcessing]);

        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)->get(route('admin.store-orders.index'))
            ->assertInertia(fn ($page) => $page->has('orders.data', 2));
        $this->actingAs($staff)->get(route('admin.store-orders.index', ['partner' => $other->public_id]))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.storefront.name', 'Beta shop')->where('orders.data.0.partner.name', $other->name));
        $this->actingAs($staff)->get(route('admin.store-orders.index', ['storefront' => $this->website->public_id]))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.storefront.name', 'Alpha shop'));
        $this->actingAs($staff)->get(route('admin.store-orders.index', ['step' => 'processing']))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', $beta->reference));
    });

    it('lets staff take only the next step through the route, and refuses the rest on the server', function () {
        $order = storeOrderPlace(codeNeeded: false);
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)->post(route('admin.store-orders.advance', [$order->public_id, 'dispatch']))
            ->assertSessionHasErrors('action');
        $this->actingAs($staff)->post(route('admin.store-orders.advance', [$order->public_id, 'start_processing']))
            ->assertSessionHasNoErrors();
        $this->actingAs($staff)->get(route('admin.store-orders.show', $order->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('timeline.next_action.label', 'Start Packing')
                ->where('timeline.steps.2.state', 'current')
                ->where('timeline.steps.3.state', 'upcoming'));

        expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    });
});
