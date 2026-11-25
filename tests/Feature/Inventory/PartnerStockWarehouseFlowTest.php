<?php

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Courier\Queries\ResolveShipmentWarehouse;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerLotWarehouseScope;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderSource;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Where a Partner's units are, carried by the application's own actions: central
 * stock in a Banij warehouse, the wholesale purchase reserving and paying for it,
 * the Partner lot it opens, and a Storefront order taking those units -- with no
 * allocation made by hand anywhere on the way.
 */

beforeEach(function () {
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $this->product = websiteTestProduct();
    $this->tejgaon = Warehouse::create(['code' => 'FLW-T-'.Str::upper(Str::random(4)), 'name' => 'Tejgaon', 'is_active' => true, 'is_default' => true]);
    $this->tejgaonStock = StockItem::create(['warehouse_id' => $this->tejgaon->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($this->tejgaonStock, null, StockBucket::Available, 50, StockMovementType::Adjustment);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->website = Website::factory()->forAccount($this->account)->active()->create(['sms_verification_enabled' => false]);

    WebsiteProduct::create([
        'website_id' => $this->website->id, 'business_account_id' => $this->account->id, 'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published, 'sync_status' => WebsiteSyncStatus::Pending, 'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT), 'published_at' => now(),
    ]);
});

/**
 * The Partner buys `$units` wholesale, reserved and paid through the real actions.
 */
function partnerStockFlowBuy(int $units, string $unitPrice = '1500.00'): Order
{
    $order = Order::factory()->create(['business_account_id' => test()->account->id, 'source' => OrderSource::ErpWholesale]);
    $unit = Money::fromDecimal($unitPrice, Currency::BDT);

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
    $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'flow-'.Str::random(8)])->save();

    app(ConfirmOrderPayment::class)->handle($payment->refresh());

    return $order->refresh();
}

function partnerStockFlowPlace(int $quantity = 2): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    $total = bcmul('2600.00', (string) $quantity, 2);

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        deliveryLocation: deliveryTestLocation(),
        reference: 'SF-FLW-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => $quantity]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: ['subtotal' => $money($total), 'discount' => $money('0.00'), 'shipping' => $money('0.00'), 'tax' => $money('0.00'), 'grand_total' => $money($total)],
        paymentMethod: 'cod',
    ));

    return $order->refresh();
}

/**
 * @return array<int, int> units per lot a hold took, net of anything given back
 */
function partnerStockFlowHeld(int $reservationId): array
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

it('carries the wholesale warehouse to the lot, the Storefront hold and the parcel, with no manual allocation', function () {
    $purchase = partnerStockFlowBuy(10);
    $line = $purchase->items()->sole();
    $wholesaleWarehouse = StockItem::query()->findOrFail(StockReservation::query()->findOrFail($line->stock_reservation_id)->stock_item_id)->warehouse_id;
    $lot = DB::table('partner_stock_cost_lots')->where('source_order_item_id', $line->id)->sole();

    expect($wholesaleWarehouse)->toBe($this->tejgaon->id)
        ->and($lot->warehouse_id)->toBe($wholesaleWarehouse)
        ->and($lot->warehouse_evidence)->toBe('wholesale_reservation')
        ->and($lot->unit_cost)->toBe('1500.00');

    $order = partnerStockFlowPlace();

    expect(partnerStockFlowHeld($order->items()->sole()->stock_reservation_id))->toBe([$lot->id => 2])
        ->and(DB::table('order_item_allocations')->where('order_id', $order->id)->count())->toBe(0)
        ->and(app(ResolveShipmentWarehouse::class)->handle($order)->single())->toBe($this->tejgaon->id);
});

it('keeps the same Product bought from two warehouses, at two prices, in separate lots, and fills one order from one warehouse', function () {
    $chattogram = Warehouse::create(['code' => 'FLW-C-'.Str::upper(Str::random(4)), 'name' => 'Chattogram', 'is_active' => true]);
    $ctgStock = StockItem::create(['warehouse_id' => $chattogram->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($ctgStock, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    partnerStockFlowBuy(1, '1500.00');                                        // Tejgaon, the default
    app(StockLedger::class)->move($this->tejgaonStock, StockBucket::Available, null, 49, StockMovementType::Adjustment);
    partnerStockFlowBuy(5, '1400.00');                                        // only Chattogram has stock now

    $lots = DB::table('partner_stock_cost_lots')->where('business_account_id', $this->account->id)->orderBy('id')->get();

    expect($lots)->toHaveCount(2)
        ->and([$lots[0]->warehouse_id, $lots[0]->unit_cost])->toBe([$this->tejgaon->id, '1500.00'])
        ->and([$lots[1]->warehouse_id, $lots[1]->unit_cost])->toBe([$chattogram->id, '1400.00']);

    // Two units: the older Tejgaon lot holds only one, so the whole order comes
    // from Chattogram -- never one unit from each warehouse.
    $order = partnerStockFlowPlace(2);

    expect(partnerStockFlowHeld($order->items()->sole()->stock_reservation_id))->toBe([$lots[1]->id => 2])
        ->and(app(ResolveShipmentWarehouse::class)->handle($order)->single())->toBe($chattogram->id);
});

it('prefers the warehouse holding the oldest stock when several could fill the order', function () {
    $chattogram = Warehouse::create(['code' => 'FLW-C-'.Str::upper(Str::random(4)), 'name' => 'Chattogram', 'is_active' => true]);
    $ctgStock = StockItem::create(['warehouse_id' => $chattogram->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($ctgStock, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    $older = partnerStockFlowBuy(5);                                          // Tejgaon first
    $this->travel(1)->hours();
    app(StockLedger::class)->move($this->tejgaonStock, StockBucket::Available, null, 45, StockMovementType::Adjustment);
    partnerStockFlowBuy(5);                                                   // then Chattogram

    $order = partnerStockFlowPlace(2);
    $olderLot = DB::table('partner_stock_cost_lots')->where('source_order_item_id', $older->items()->sole()->id)->sole();

    expect(partnerStockFlowHeld($order->items()->sole()->stock_reservation_id))->toBe([$olderLot->id => 2])
        ->and(app(ResolveShipmentWarehouse::class)->handle($order)->single())->toBe($this->tejgaon->id);
});

it('gives a cancelled hold back to the same lot, in the same warehouse', function () {
    partnerStockFlowBuy(10);
    $order = partnerStockFlowPlace();
    $reservationId = $order->items()->sole()->stock_reservation_id;
    $lotId = array_key_first(partnerStockFlowHeld($reservationId));

    app(StockReservations::class)->release(StockReservation::query()->findOrFail($reservationId), 'Customer cancelled.');

    $lot = DB::table('partner_stock_cost_lots')->where('id', $lotId)->sole();
    expect($lot->remaining_quantity)->toBe(10)
        ->and($lot->warehouse_id)->toBe($this->tejgaon->id)
        ->and(partnerStockFlowHeld($reservationId))->toBe([]);
});

it('holds an order for staff when no single warehouse can fill it', function () {
    $chattogram = Warehouse::create(['code' => 'FLW-C-'.Str::upper(Str::random(4)), 'name' => 'Chattogram', 'is_active' => true]);
    $ctgStock = StockItem::create(['warehouse_id' => $chattogram->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($ctgStock, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    partnerStockFlowBuy(1);                                                   // one unit in Tejgaon
    app(StockLedger::class)->move($this->tejgaonStock, StockBucket::Available, null, 49, StockMovementType::Adjustment);
    partnerStockFlowBuy(1);                                                   // one unit in Chattogram

    // Two units exist, one per warehouse: the order is taken, and then held.
    $order = partnerStockFlowPlace(2);

    expect(app(ResolveShipmentWarehouse::class)->handle($order)->refusal())->toBe('multiple_warehouses');
});

it('refuses, rather than splits, when the chosen warehouse runs short', function () {
    partnerStockFlowBuy(2);

    expect(fn () => app(PartnerLotWarehouseScope::class)->within(
        $this->tejgaon->id + 999,
        fn () => app(StockReservations::class)->reserveFromPartner($this->account, $this->product, null, 2, ReservationKind::CashOnDelivery, 'SHORT-'.Str::random(6)),
    ))->toThrow(InventoryRefused::class);

    expect(DB::table('partner_stock_cost_lots')->where('business_account_id', $this->account->id)->sum('remaining_quantity'))->toBe(2)
        ->and((int) DB::table('partner_stock_items')->where('business_account_id', $this->account->id)->value('available'))->toBe(2);
});
