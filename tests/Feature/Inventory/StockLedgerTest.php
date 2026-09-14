<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Stock movements: the history of every change to central stock (P3-23, §19).
 *
 * Nothing but the ledger writes a figure, and the ledger always writes the
 * movement that explains it, with every bucket before and after, in the same
 * transaction. The history is append-only and chains: each movement starts
 * where the previous one ended, and the last one ends where the item stands.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $category = Category::create(['name' => 'Kitchen']);
    $this->product = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);
    $this->ledger = app(StockLedger::class);
});

function stockLedgerMove(?StockBucket $from, ?StockBucket $to, int $quantity, ?string $key = null, ?string $reason = null): StockMovement
{
    return test()->ledger->move(
        test()->item,
        $from,
        $to,
        $quantity,
        StockMovementType::Adjustment,
        new MovementContext(reason: $reason, idempotencyKey: $key),
    );
}

describe('moving stock', function () {
    it('brings stock in, moves it between buckets and takes it out, recording every bucket before and after', function () {
        $arrived = stockLedgerMove(null, StockBucket::Available, 10, reason: 'Received from supplier');
        $damaged = stockLedgerMove(StockBucket::Available, StockBucket::Damaged, 2);
        $writtenOff = stockLedgerMove(StockBucket::Damaged, null, 1);

        expect($this->item->refresh()->buckets())->toBe([
            'available' => 8, 'reserved' => 0, 'processing' => 0, 'sold' => 0, 'returned' => 0, 'damaged' => 1, 'allocated' => 0,
        ])
            ->and($arrived->before['available'])->toBe(0)
            ->and($arrived->after['available'])->toBe(10)
            ->and($arrived->reason)->toBe('Received from supplier')
            ->and($damaged->from_bucket)->toBe(StockBucket::Available)
            ->and($damaged->to_bucket)->toBe(StockBucket::Damaged)
            ->and($damaged->after)->toMatchArray(['available' => 8, 'damaged' => 2])
            ->and($writtenOff->to_bucket)->toBeNull()
            ->and($writtenOff->after['damaged'])->toBe(1)
            ->and($writtenOff->product_id)->toBe($this->product->id)
            ->and($writtenOff->warehouse_id)->toBe($this->warehouse->id);
    });

    it('refuses taking more than a bucket holds, and writes nothing', function () {
        stockLedgerMove(null, StockBucket::Available, 3);

        expect(fn () => stockLedgerMove(StockBucket::Available, StockBucket::Reserved, 4))
            ->toThrow(InventoryRefused::class, __('inventory.refused.insufficient', [
                'bucket' => __('inventory.buckets.available'), 'held' => 3, 'requested' => 4,
            ]));

        expect($this->item->refresh()->available)->toBe(3)
            ->and($this->item->reserved)->toBe(0)
            ->and(StockMovement::query()->count())->toBe(1);
    });

    it('refuses a movement that moves nothing', function (?StockBucket $from, ?StockBucket $to, int $quantity) {
        expect(fn () => stockLedgerMove($from, $to, $quantity))->toThrow(InvalidArgumentException::class);

        expect(StockMovement::query()->count())->toBe(0);
    })->with([
        'no units' => [null, StockBucket::Available, 0],
        'negative units' => [null, StockBucket::Available, -5],
        'no bucket at all' => [null, null, 1],
        'a bucket into itself' => [StockBucket::Available, StockBucket::Available, 1],
    ]);

    it('carries out one command once, however often it is retried', function () {
        $first = stockLedgerMove(null, StockBucket::Available, 5, key: 'receipt:42');
        $again = stockLedgerMove(null, StockBucket::Available, 5, key: 'receipt:42');

        expect($again->id)->toBe($first->id)
            ->and($this->item->refresh()->available)->toBe(5)
            ->and(StockMovement::query()->count())->toBe(1);
    });

    it('keeps a history that chains from zero to where the item stands', function () {
        stockLedgerMove(null, StockBucket::Available, 20);
        stockLedgerMove(StockBucket::Available, StockBucket::Reserved, 6);
        stockLedgerMove(StockBucket::Reserved, StockBucket::Processing, 4);
        stockLedgerMove(StockBucket::Reserved, StockBucket::Available, 2);
        stockLedgerMove(StockBucket::Processing, StockBucket::Sold, 4);

        $movements = StockMovement::query()->where('stock_item_id', $this->item->id)->orderBy('id')->get();
        $expected = array_fill_keys(StockBucket::values(), 0);

        foreach ($movements as $movement) {
            expect($movement->before)->toBe($expected);
            $expected = $movement->after;
        }

        expect($this->item->refresh()->buckets())->toBe($expected)
            ->and($expected['sold'])->toBe(4)
            ->and($expected['available'])->toBe(16);
    });
});

describe('the database', function () {
    beforeEach(function () {
        $this->movement = stockLedgerMove(null, StockBucket::Available, 5);
    });

    it('never edits a movement', function () {
        expect(fn () => DB::table('stock_movements')->where('id', $this->movement->id)->update(['quantity' => 50]))
            ->toThrow(QueryException::class);
    });

    it('never removes a movement', function () {
        expect(fn () => DB::table('stock_movements')->where('id', $this->movement->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses a movement written outside the ledger that makes no sense', function (array $attributes, string $constraint) {
        expect(fn () => DB::table('stock_movements')->insert([
            'public_id' => (string) Str::ulid(),
            'stock_item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'type' => 'adjustment',
            'to_bucket' => 'available',
            'quantity' => 1,
            'before' => '{}',
            'after' => '{}',
            ...$attributes,
        ]))->toThrow(QueryException::class, $constraint);
    })->with([
        'no units' => [['quantity' => 0], 'stock_movements_quantity_positive'],
        'an invented cause' => [['type' => 'found_it'], 'stock_movements_type_known'],
        'an invented bucket' => [['to_bucket' => 'lost'], 'stock_movements_buckets_known'],
        'nowhere to nowhere' => [['to_bucket' => null], 'stock_movements_moves_something'],
    ]);
});

describe('the history screen', function () {
    beforeEach(function () {
        $this->actor = testPlatformStaff(PlatformRole::InventoryManager);

        $this->ledger->move($this->item, null, StockBucket::Available, 12, StockMovementType::Adjustment, new MovementContext(
            reason: 'Opening count', actorId: $this->actor->id,
        ));
        $this->ledger->move($this->item, StockBucket::Available, StockBucket::Damaged, 2, StockMovementType::Adjustment, new MovementContext(
            reason: 'Dropped in transit', actorId: $this->actor->id,
        ));
    });

    it('shows staff who may view stock every movement, newest first, with figures before and after', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/stock-item')
                ->where('item.sku', 'FW-KT')
                ->where('item.buckets.available', 10)
                ->where('item.buckets.damaged', 2)
                ->has('movements.data', 2)
                ->where('movements.data.0.reason', 'Dropped in transit')
                ->where('movements.data.0.from', 'available')
                ->where('movements.data.0.to', 'damaged')
                ->where('movements.data.0.before.available', 12)
                ->where('movements.data.0.after.available', 10)
                ->where('movements.data.0.actor', $this->actor->name)
                ->where('movements.data.1.type_label', __('inventory.movement_types.adjustment')));
    });

    it('refuses a partner, and answers an unknown item with 404', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertForbidden();

        $this->actingAs($this->actor)
            ->get(route('admin.inventory.stock.show', '01JZZZZZZZZZZZZZZZZZZZZZZZ'))
            ->assertNotFound();
    });
});
