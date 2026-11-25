<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\OpenPartnerStockCostLots;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\PartnerStockCostLot;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\PartnerStockLotMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\PartnerStockCostLots;
use App\Domain\Inventory\PartnerStockReservations;
use App\Domain\Order\Models\Order;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * What each unit of a Partner's own stock cost them, first in, first out.
 */

beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->product = websiteTestProduct();
});

function costLotsPurchase(BusinessAccount $account, Product $product, int $quantity, string $unit, string $discount = '0.00', string $taxIncluded = '0.00', ?CarbonImmutable $paidAt = null): Order
{
    $order = Order::factory()->create(['business_account_id' => $account->id, 'paid_at' => $paidAt ?? now()]);
    $subtotal = Money::fromDecimal($unit, Currency::BDT)->multipliedBy($quantity);

    $order->items()->create([
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal($unit, Currency::BDT),
        'line_subtotal' => $subtotal,
        'discount' => Money::fromDecimal($discount, Currency::BDT),
        'tax_included' => Money::fromDecimal($taxIncluded, Currency::BDT),
        'line_total' => $subtotal->minus(Money::fromDecimal($discount, Currency::BDT)),
    ]);

    app(ReceivePartnerPurchase::class)->handle($order->refresh());

    return $order;
}

function costLotsHold(BusinessAccount $account, Product $product, int $quantity): int
{
    return app(PartnerStockReservations::class)
        ->hold($account, $product->id, null, $product->sku, $quantity, ReservationKind::OnlinePayment, 'T-'.Str::random(8))
        ->id;
}

function costLotsItem(BusinessAccount $account, Product $product): PartnerStockItem
{
    return PartnerStockItem::query()->where('business_account_id', $account->id)->where('product_id', $product->id)->firstOrFail();
}

it('gives a paid purchase line one lot, costed net of discount and without tax', function () {
    costLotsPurchase($this->account, $this->product, 4, '1000.00', discount: '200.00', taxIncluded: '100.00');

    $lot = PartnerStockCostLot::query()->sole();

    expect($lot->purchased_quantity)->toBe(4)
        ->and($lot->remaining_quantity)->toBe(4)
        ->and($lot->net_cost->toDecimal())->toBe('3700.00')
        ->and($lot->unit_cost->toDecimal())->toBe('925.00')
        ->and($lot->cost_is_known)->toBeTrue();
});

it('gives the same purchase line no second lot when its payment settles again', function () {
    $order = costLotsPurchase($this->account, $this->product, 2, '1000.00');
    app(ReceivePartnerPurchase::class)->handle($order->refresh());

    expect(PartnerStockCostLot::query()->count())->toBe(1)
        ->and(PartnerStockCostLot::query()->sole()->remaining_quantity)->toBe(2);
});

it('takes a hold from the oldest lots first and records each lot and quantity', function () {
    costLotsPurchase($this->account, $this->product, 2, '1000.00', paidAt: CarbonImmutable::parse('2026-10-01'));
    costLotsPurchase($this->account, $this->product, 3, '1300.00', paidAt: CarbonImmutable::parse('2026-10-02'));

    $reservation = costLotsHold($this->account, $this->product, 3);
    $allocations = app(PartnerStockCostLots::class)->allocationsFor($reservation);

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]['quantity'])->toBe(2)
        ->and($allocations[0]['cost']->toDecimal())->toBe('2000.00')
        ->and($allocations[1]['quantity'])->toBe(1)
        ->and($allocations[1]['cost']->toDecimal())->toBe('1300.00')
        ->and((int) PartnerStockCostLot::query()->sum('remaining_quantity'))->toBe(costLotsItem($this->account, $this->product)->available);
});

it('adds the units of a lot back to exactly what the line cost, whatever the rounding', function () {
    // ৳2,999.99 for 3 units does not divide into whole poisha.
    costLotsPurchase($this->account, $this->product, 3, '1000.00', discount: '0.01');

    $first = costLotsHold($this->account, $this->product, 1);
    $second = costLotsHold($this->account, $this->product, 1);
    $third = costLotsHold($this->account, $this->product, 1);
    $lots = app(PartnerStockCostLots::class);

    $total = collect([$first, $second, $third])
        ->map(fn (int $id) => $lots->allocationsFor($id)->first()['cost'])
        ->reduce(fn (Money $sum, Money $cost) => $sum->plus($cost), Money::zero(Currency::BDT));

    expect($total->toDecimal())->toBe('2999.99');
});

it('gives a released hold back to the very lots it came from, once', function () {
    costLotsPurchase($this->account, $this->product, 2, '1000.00', paidAt: CarbonImmutable::parse('2026-10-01'));
    costLotsPurchase($this->account, $this->product, 2, '1300.00', paidAt: CarbonImmutable::parse('2026-10-02'));
    $reservation = costLotsHold($this->account, $this->product, 3);

    $held = StockReservation::query()->findOrFail($reservation);
    app(PartnerStockReservations::class)->end($held, StockReservationStatus::Released, 'customer cancelled');
    app(PartnerStockReservations::class)->end($held, StockReservationStatus::Released, 'customer cancelled');

    expect(PartnerStockCostLot::query()->orderBy('purchased_at')->pluck('remaining_quantity')->all())->toBe([2, 2])
        ->and(PartnerStockLotMovement::query()->where('type', 'released')->count())->toBe(2);
});

it('puts a sold unit coming back onto the shelf into a lot the sale took it from, newest first', function () {
    costLotsPurchase($this->account, $this->product, 1, '1000.00', paidAt: CarbonImmutable::parse('2026-10-01'));
    costLotsPurchase($this->account, $this->product, 2, '1300.00', paidAt: CarbonImmutable::parse('2026-10-02'));
    $reservationId = costLotsHold($this->account, $this->product, 2);

    $reservation = StockReservation::query()->findOrFail($reservationId);
    app(PartnerStockReservations::class)->end($reservation, StockReservationStatus::Committed, 'paid');

    $item = costLotsItem($this->account, $this->product);
    $stock = app(PartnerStock::class);
    $stock->move($item, StockBucket::Processing, StockBucket::Sold, 2, PartnerStockMovementType::Adjustment, new MovementContext(idempotencyKey: 'sold-'.$reservationId));

    $line = Order::factory()->create(['business_account_id' => $this->account->id])->items()->create([
        'line_number' => 1, 'product_id' => $this->product->id, 'sku' => $this->product->sku, 'product_name' => 'x',
        'quantity' => 2, 'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('2000.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('4000.00', Currency::BDT), 'line_total' => Money::fromDecimal('4000.00', Currency::BDT),
        'stock_reservation_id' => $reservationId,
    ]);

    $context = new MovementContext(sourceType: 'order_return', sourceId: 1, idempotencyKey: 'return-1', orderItemId: $line->id);
    $stock->move($item->refresh(), StockBucket::Sold, StockBucket::Available, 1, PartnerStockMovementType::ReturnReceived, $context);
    $stock->move($item->refresh(), StockBucket::Sold, StockBucket::Available, 1, PartnerStockMovementType::ReturnReceived, $context);

    // The sale took the ৳1,000 unit and one ৳1,300 unit; the ৳1,300 one comes back.
    expect(PartnerStockCostLot::query()->orderBy('purchased_at')->pluck('remaining_quantity')->all())->toBe([0, 2])
        ->and(app(PartnerStockCostLots::class)->keptCost($line, 1)?->toDecimal())->toBe('1000.00');
});

it('opens lots for stock bought before lots existed: one known cost only when every purchase shared one price', function () {
    $single = websiteTestProduct();
    $mixed = websiteTestProduct();

    costLotsPurchase($this->account, $single, 2, '1000.00');
    costLotsPurchase($this->account, $single, 3, '1000.00');
    costLotsPurchase($this->account, $mixed, 1, '1000.00');
    costLotsPurchase($this->account, $mixed, 1, '1200.00');

    // As if those purchases happened before lots existed.
    $pdo = DB::connection()->getPdo();
    $pdo->exec('ALTER TABLE partner_stock_lot_movements DISABLE TRIGGER partner_stock_lot_movements_no_delete');
    $pdo->exec('ALTER TABLE partner_stock_cost_lots DISABLE TRIGGER partner_stock_cost_lots_no_delete');
    DB::table('partner_stock_lot_movements')->delete();
    DB::table('partner_stock_cost_lots')->delete();
    $pdo->exec('ALTER TABLE partner_stock_lot_movements ENABLE TRIGGER partner_stock_lot_movements_no_delete');
    $pdo->exec('ALTER TABLE partner_stock_cost_lots ENABLE TRIGGER partner_stock_cost_lots_no_delete');

    $dryRun = app(OpenPartnerStockCostLots::class)->handle(false);
    expect(PartnerStockCostLot::query()->count())->toBe(0)
        ->and($dryRun['opening_units'])->toBe(7)
        ->and($dryRun['unknown_cost_units'])->toBe(2);

    app(OpenPartnerStockCostLots::class)->handle(true);
    app(OpenPartnerStockCostLots::class)->handle(true);

    $singleOpening = PartnerStockCostLot::query()->where('product_id', $single->id)->where('provenance', 'opening')->sole();
    $mixedOpening = PartnerStockCostLot::query()->where('product_id', $mixed->id)->where('provenance', 'opening')->sole();

    expect($singleOpening->remaining_quantity)->toBe(5)
        ->and($singleOpening->unit_cost?->toDecimal())->toBe('1000.00')
        ->and($mixedOpening->remaining_quantity)->toBe(2)
        ->and($mixedOpening->cost_is_known)->toBeFalse()
        ->and(PartnerStockCostLot::query()->where('provenance', 'purchase')->where('remaining_quantity', 0)->count())->toBe(4);
});
