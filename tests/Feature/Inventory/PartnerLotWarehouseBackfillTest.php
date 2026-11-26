<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Data\WarehouseEvidence;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Proving, for Partner stock bought before lots named their warehouse, which
 * Banij warehouse holds it -- and assigning it only where that is proven.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->product = websiteTestProduct();
    $this->tejgaon = Warehouse::create(['code' => 'TJG-1', 'name' => 'Tejgaon', 'is_active' => true]);
    $this->chattogram = Warehouse::create(['code' => 'CTG-1', 'name' => 'Chattogram', 'is_active' => true]);
});

/**
 * A historical paid wholesale purchase: credited to the Partner while nothing
 * yet named its warehouse -- so its lot holds none, as every lot opened before
 * lots carried a warehouse does -- with the line's allocation to `$sources`
 * (none for a line nothing traces) on record afterwards.
 *
 * @param  list<Warehouse>  $sources
 */
function partnerLotBackfillPurchase(array $sources, int $quantity = 4): OrderItem
{
    $order = Order::factory()->create(['business_account_id' => test()->account->id, 'paid_at' => now()]);
    $unit = Money::fromDecimal('1500.00', Currency::BDT);

    /** @var OrderItem $line */
    $line = $order->items()->create([
        'line_number' => 1, 'product_id' => test()->product->id, 'sku' => test()->product->sku,
        'product_name' => test()->product->name, 'quantity' => $quantity, 'currency_code' => 'BDT',
        'unit_price' => $unit, 'line_subtotal' => $unit->multipliedBy($quantity), 'line_total' => $unit->multipliedBy($quantity),
    ]);

    app(ReceivePartnerPurchase::class)->handle($order->refresh());

    $share = intdiv($quantity, max(1, count($sources)));

    foreach ($sources as $warehouse) {
        OrderItemAllocation::create([
            'order_id' => $order->id, 'order_item_id' => $line->id,
            'source_type' => AllocationSourceType::Warehouse,
            'warehouse_id' => $warehouse->id,
            'quantity' => $share, 'currency_code' => 'BDT', 'status' => AllocationStatus::Active, 'allocated_at' => now(),
            'unit_cost' => Money::fromDecimal('1000.00', Currency::BDT), 'platform_rate' => $unit,
            'expected_margin' => Money::fromDecimal(bcmul('500.00', (string) $share, 2), Currency::BDT),
            'idempotency_key' => 'alloc:'.Str::ulid(),
        ]);
    }

    return $line;
}

function partnerLotBackfillLot(OrderItem $line): object
{
    return DB::table('partner_stock_cost_lots')->where('source_order_item_id', $line->id)->sole();
}

it('classifies without changing anything on a dry run', function () {
    partnerLotBackfillPurchase([$this->tejgaon]);
    partnerLotBackfillPurchase([$this->tejgaon, $this->chattogram]);
    partnerLotBackfillPurchase([]);

    $this->artisan('inventory:partner-lot-warehouses')
        ->expectsTable(['Already assigned', 'Safe', 'Ambiguous', 'Missing'], [[0, 1, 1, 1]])
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertSuccessful();

    expect(DB::table('partner_stock_cost_lots')->whereNotNull('warehouse_id')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'inventory.partner_lot_warehouses_assigned')->count())->toBe(0);
});

it('assigns only the lot whose single warehouse is proven, and leaves the rest for review', function () {
    $safe = partnerLotBackfillPurchase([$this->tejgaon]);
    $split = partnerLotBackfillPurchase([$this->tejgaon, $this->chattogram]);
    $untraced = partnerLotBackfillPurchase([]);

    $this->artisan('inventory:partner-lot-warehouses', ['--apply' => true])
        ->expectsOutputToContain('Assigned a warehouse to 1 safe lot(s).')
        ->assertSuccessful();

    expect(partnerLotBackfillLot($safe))
        ->warehouse_id->toBe($this->tejgaon->id)
        ->warehouse_evidence->toBe('wholesale_allocation')
        ->and(partnerLotBackfillLot($split)->warehouse_id)->toBeNull()
        ->and(partnerLotBackfillLot($untraced)->warehouse_id)->toBeNull();

    $audit = AuditLog::query()->where('action', 'inventory.partner_lot_warehouses_assigned')->sole();
    expect($audit->after['assigned'])->toBe(1)
        ->and($audit->after['left_for_review'])->toBe(2);
});

it('never treats units no record places in a Banij warehouse as held there', function () {
    // A Supplier-filled share, or any share no allocation or reservation names.
    $partly = new WarehouseEvidence([$this->tejgaon->id], unproven: true, source: WarehouseEvidence::SOURCE_ALLOCATION);
    $none = new WarehouseEvidence([], unproven: true, source: null);

    expect($partly->classification())->toBe(WarehouseEvidence::AMBIGUOUS)
        ->and($partly->warehouse())->toBeNull()
        ->and($none->classification())->toBe(WarehouseEvidence::MISSING)
        ->and($none->warehouse())->toBeNull();
});

it('does not count a released allocation as where the units went', function () {
    $line = partnerLotBackfillPurchase([$this->tejgaon]);
    DB::table('order_item_allocations')->where('order_item_id', $line->id)->update(['status' => 'released', 'released_at' => now()]);

    $this->artisan('inventory:partner-lot-warehouses', ['--apply' => true])->assertSuccessful();

    expect(partnerLotBackfillLot($line)->warehouse_id)->toBeNull();
});

it('never picks the only warehouse there is when nothing proves the units are there', function () {
    $this->chattogram->forceFill(['is_active' => false])->save();
    $untraced = partnerLotBackfillPurchase([]);

    $this->artisan('inventory:partner-lot-warehouses', ['--apply' => true])->assertSuccessful();

    expect(partnerLotBackfillLot($untraced)->warehouse_id)->toBeNull();
});

it('is safe to run again, and a proven warehouse cannot be changed afterwards', function () {
    $safe = partnerLotBackfillPurchase([$this->tejgaon]);

    $this->artisan('inventory:partner-lot-warehouses', ['--apply' => true])->assertSuccessful();
    $this->artisan('inventory:partner-lot-warehouses', ['--apply' => true])
        ->expectsTable(['Already assigned', 'Safe', 'Ambiguous', 'Missing'], [[1, 0, 0, 0]])
        ->assertSuccessful();

    expect(fn () => DB::transaction(fn () => DB::table('partner_stock_cost_lots')
        ->where('source_order_item_id', $safe->id)
        ->update(['warehouse_id' => $this->chattogram->id])))
        ->toThrow(QueryException::class);

    expect(partnerLotBackfillLot($safe)->warehouse_id)->toBe($this->tejgaon->id);
});
