<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Courier\Jobs\IssueShipmentLabelJob;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\AdvanceStoreOrder;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderAction;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Payment\Enums\PaymentStatus;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * One Partner-stock parcel from end to end, through the application's own
 * actions only: central stock in a Banij warehouse, the Partner's wholesale
 * purchase reserving and paying for it, the Partner stock it credits, a
 * Storefront customer's order taking those units, dispatch, a (faked) Steadfast
 * booking, and the shipping label. Nothing on the path -- reservation, lot,
 * shipment or label -- is written to the database by the test itself.
 *
 * The warehouse must survive every hand-off unchanged:
 * wholesale reservation → acquisition lot → Storefront hold → shipment → label.
 */

require_once __DIR__.'/ShipmentLabelFixtures.php';

const PARTNER_JOURNEY_STEADFAST = 'https://portal.steadfast.com.bd/api/v1';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);
    shipmentLabelEnableSteadfast();
    Http::preventStrayRequests();

    $this->staff = testPlatformStaff(PlatformRole::OrderManager);
    $this->staff->assignRole(PlatformRole::InventoryManager->value);
    $this->courierStaff = testPlatformStaff(PlatformRole::CourierManager);

    // 1. Central stock in a real Banij warehouse, approved for courier labels.
    $this->warehouse = shipmentLabelWarehouse(['name' => 'Banij Tejgaon Hub']);
    $this->product = websiteTestProduct();
    $this->centralStock = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($this->centralStock, null, StockBucket::Available, 50, StockMovementType::Adjustment);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->website = Website::factory()->forAccount($this->account)->active()->create([
        'name' => 'Rupkotha Fashion', 'contact_phone' => '01911111111', 'primary_color' => '#c2410c',
        'contact_address' => 'STOREFRONT-CONTACT-ADDRESS must never print',
    ]);
});

/**
 * 2–5. The Partner buys `$units` wholesale: the line reserves central stock the
 * way a wholesale checkout does, and the payment settling commits it and
 * credits the Partner.
 */
function partnerJourneyBuy(int $units): Order
{
    $order = Order::factory()->create(['business_account_id' => test()->account->id, 'source' => OrderSource::ErpWholesale]);
    $unit = Money::fromDecimal('1500.00', Currency::BDT);

    $reservation = app(StockReservations::class)->reserve(
        test()->product, null, $units, ReservationKind::OnlinePayment, $order->reference.'-L1', test()->account,
    );

    $order->items()->create([
        'line_number' => 1, 'product_id' => test()->product->id, 'sku' => test()->product->sku,
        'product_name' => test()->product->name, 'quantity' => $units, 'currency_code' => 'BDT',
        'unit_price' => $unit, 'line_subtotal' => $unit->multipliedBy($units), 'line_total' => $unit->multipliedBy($units),
        'stock_reservation_id' => $reservation->id,
    ]);

    $payment = $order->payment;
    $payment->transitionTo(PaymentStatus::Initiated)->save();
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'journey-'.Str::random(8)])->save();

    app(ConfirmOrderPayment::class)->handle($payment->refresh());

    return $order->refresh();
}

/**
 * 7. The Partner's Storefront offers the product.
 */
function partnerJourneySelect(): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => test()->website->id, 'business_account_id' => test()->account->id,
        'product_id' => test()->product->id, 'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending, 'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT), 'published_at' => now(),
    ]);
}

/**
 * 8. A customer orders two units, the way the storefront places an order.
 */
function partnerJourneyPlace(): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12, Road 5, Block C', 'area' => 'Mirpur 10', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postcode' => '1216', 'country' => 'BD'];
    test()->website->forceFill(['sms_verification_enabled' => false])->save();

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        deliveryLocation: deliveryTestLocation(),
        reference: 'SF-JRN-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+8801712345678'),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => 2]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: ['subtotal' => $money('5200.00'), 'discount' => $money('0.00'), 'shipping' => $money('0.00'), 'tax' => $money('0.00'), 'grand_total' => $money('5200.00')],
        paymentMethod: 'cod',
    ));

    return $order->refresh();
}

/**
 * The lots a hold drew on, net of anything given back.
 *
 * @return array<int, int> units per lot id
 */
function partnerJourneyHeldLots(int $reservationId): array
{
    return DB::table('partner_stock_lot_movements')
        ->where('stock_reservation_id', $reservationId)
        ->whereIn('type', ['allocated', 'released'])
        ->groupBy('partner_stock_cost_lot_id')
        ->havingRaw('-SUM(quantity) > 0')
        ->pluck(DB::raw('-SUM(quantity)'), 'partner_stock_cost_lot_id')
        ->map(fn ($units) => (int) $units)
        ->all();
}

it('carries one Banij warehouse from the wholesale purchase to the printed label', function () {
    // 2–6. Wholesale purchase → acquisition lot inheriting its warehouse.
    $purchase = partnerJourneyBuy(10);
    $wholesaleReservation = StockReservation::query()->findOrFail($purchase->items()->sole()->stock_reservation_id);
    $wholesaleWarehouse = StockItem::query()->findOrFail($wholesaleReservation->stock_item_id)->warehouse_id;

    $lot = DB::table('partner_stock_cost_lots')->where('source_order_item_id', $purchase->items()->sole()->id)->sole();

    expect($wholesaleWarehouse)->toBe($this->warehouse->id)
        ->and($lot->warehouse_id)->toBe($wholesaleWarehouse)
        ->and($lot->warehouse_evidence)->toBe('wholesale_reservation');

    // 7–8. The Storefront sells two units of it.
    partnerJourneySelect();
    $order = partnerJourneyPlace();
    $line = $order->items()->sole();

    // 9. The hold took them from that lot, and so from that warehouse.
    expect(partnerJourneyHeldLots($line->stock_reservation_id))->toBe([$lot->id => 2]);

    // 10. Dispatched through the real steps.
    foreach ([StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked, StoreOrderAction::Dispatch] as $action) {
        app(AdvanceStoreOrder::class)->handle($order->refresh(), $action, $this->staff);
    }

    // 11. Booked with Steadfast -- faked; nothing leaves the test.
    Http::fake([PARTNER_JOURNEY_STEADFAST.'/create_order' => Http::response(shipmentLabelSteadfastCreated($order->refresh(), '1424107', '15BAEB8A'))]);
    app(BookSteadfastShipment::class)->handle($order->refresh(), $this->courierStaff);

    // 12–13. The shipment, and the label issued for it after commit.
    $shipment = Shipment::query()->where('order_id', $order->id)->sole();
    $label = ShipmentLabel::query()->where('shipment_id', $shipment->id)->sole();

    expect($shipment->warehouse_id)->toBe($lot->warehouse_id)
        ->and($label->warehouse_id)->toBe($shipment->warehouse_id)
        ->and($label->warehouse_name)->toBe('Banij Tejgaon Hub')
        ->and($label->warehouse_return_address['line'])->toBe('12 Tejgaon Industrial Area')
        ->and($label->barcode_value)->toBe('15BAEB8A');

    // 14. What prints.
    $html = $this->actingAs($this->courierStaff)
        ->get(route('admin.shipments.label.show', [$shipment->public_id, 'size' => '3x4']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-field="store-name">Rupkotha Fashion</div>')
        ->toContain('12 Tejgaon Industrial Area')
        ->toContain('data-value="15BAEB8A"')
        ->toContain('data-field="powered-by">Powered by Banij</footer>')
        ->not->toContain('STOREFRONT-CONTACT-ADDRESS');

    // The sale's cost is the consumed lot's own cost: two of its ten units.
    $consumed = DB::table('partner_stock_lot_movements')
        ->where('stock_reservation_id', $line->stock_reservation_id)->where('type', 'allocated')->sole();
    expect($consumed->partner_stock_cost_lot_id)->toBe($lot->id)
        ->and($consumed->cost)->toBe(bcmul((string) $lot->unit_cost, '2', 2));

    // Only one real-looking courier call was made, and it was faked.
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'create_order')))->toHaveCount(1);
});

it('reprints from the snapshot after the warehouse and the Store change', function () {
    partnerJourneyBuy(10);
    partnerJourneySelect();
    $order = partnerJourneyPlace();
    foreach ([StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked, StoreOrderAction::Dispatch] as $action) {
        app(AdvanceStoreOrder::class)->handle($order->refresh(), $action, $this->staff);
    }
    Http::fake([PARTNER_JOURNEY_STEADFAST.'/create_order' => Http::response(shipmentLabelSteadfastCreated($order->refresh()))]);
    app(BookSteadfastShipment::class)->handle($order->refresh(), $this->courierStaff);
    $shipment = Shipment::query()->where('order_id', $order->id)->sole();

    $this->warehouse->forceFill(['return_address_line' => 'Moved to Gazipur', 'courier_phone' => '01899999999'])->save();
    $this->website->forceFill(['name' => 'Renamed Store'])->save();

    $this->actingAs($this->courierStaff)
        ->get(route('admin.shipments.label.show', [$shipment->public_id, 'size' => '3x3']))
        ->assertOk()
        ->assertSee('Rupkotha Fashion')
        ->assertSee('12 Tejgaon Industrial Area')
        ->assertDontSee('Renamed Store')
        ->assertDontSee('Moved to Gazipur');
});

it('makes no second booking, shipment or label when the workflow is retried', function () {
    partnerJourneyBuy(10);
    partnerJourneySelect();
    $order = partnerJourneyPlace();
    foreach ([StoreOrderAction::StartProcessing, StoreOrderAction::StartPacking, StoreOrderAction::MarkPacked, StoreOrderAction::Dispatch] as $action) {
        app(AdvanceStoreOrder::class)->handle($order->refresh(), $action, $this->staff);
    }
    Http::fake([PARTNER_JOURNEY_STEADFAST.'/create_order' => Http::response(shipmentLabelSteadfastCreated($order->refresh()))]);
    app(BookSteadfastShipment::class)->handle($order->refresh(), $this->courierStaff);
    $shipment = Shipment::query()->where('order_id', $order->id)->sole();

    expect(fn () => app(BookSteadfastShipment::class)->handle($order->refresh(), $this->courierStaff))
        ->toThrow(CourierBookingRefused::class);
    app(IssueShipmentLabel::class)->handle($shipment, $this->courierStaff);
    IssueShipmentLabelJob::dispatchSync($shipment->id);

    expect(Shipment::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(ShipmentLabel::query()->where('shipment_id', $shipment->id)->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), 'create_order')))->toHaveCount(1);
});

it('keeps lots of the same Product from different warehouses apart, and fills one order from one of them', function () {
    $chattogram = shipmentLabelWarehouse(['name' => 'Banij Chattogram Hub', 'return_address_line' => '7 Agrabad C/A', 'return_district' => 'Chattogram']);

    // The Tejgaon stock runs out after one unit; the rest comes from Chattogram.
    app(StockLedger::class)->move($this->centralStock, StockBucket::Available, null, 49, StockMovementType::Adjustment);
    $ctgStock = StockItem::create(['warehouse_id' => $chattogram->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($ctgStock, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    $first = partnerJourneyBuy(1);   // from Tejgaon
    $second = partnerJourneyBuy(5);  // from Chattogram

    $lots = DB::table('partner_stock_cost_lots')->where('business_account_id', $this->account->id)->orderBy('id')->get();

    expect($lots)->toHaveCount(2)
        ->and($lots[0]->warehouse_id)->toBe($this->warehouse->id)
        ->and($lots[1]->warehouse_id)->toBe($chattogram->id);

    // Two units: only Chattogram holds both, so the whole order comes from there
    // -- not one unit from the older Tejgaon lot and one from Chattogram.
    partnerJourneySelect();
    $order = partnerJourneyPlace();

    expect(partnerJourneyHeldLots($order->items()->sole()->stock_reservation_id))->toBe([$lots[1]->id => 2]);
});

it('gives a cancelled hold back to the same lot, in the same warehouse', function () {
    partnerJourneyBuy(10);
    partnerJourneySelect();
    $order = partnerJourneyPlace();
    $reservationId = $order->items()->sole()->stock_reservation_id;
    $lotId = array_key_first(partnerJourneyHeldLots($reservationId));

    app(StockReservations::class)->release(StockReservation::query()->findOrFail($reservationId), 'Customer cancelled.');

    $lot = DB::table('partner_stock_cost_lots')->where('id', $lotId)->sole();
    expect($lot->remaining_quantity)->toBe(10)
        ->and($lot->warehouse_id)->toBe($this->warehouse->id)
        ->and(partnerJourneyHeldLots($reservationId))->toBe([]);
});
