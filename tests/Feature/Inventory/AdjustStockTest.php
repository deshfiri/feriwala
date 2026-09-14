<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Stock changed by hand (P3-24, §19).
 *
 * "Only the Admin or an Authorized User can directly modify Central stock." Every
 * such change names the buckets it moves between, goes through the stock ledger
 * like every other change, and is recorded three ways in one transaction: the
 * adjustment with who and why, the movement with every bucket before and after,
 * and the audit log.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $category = Category::create(['name' => 'Kitchen']);
    $product = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id]);
});

/**
 * Put units in a bucket without an adjustment, as setup.
 */
function adjustStockSeed(StockBucket $bucket, int $quantity): void
{
    app(StockLedger::class)->move(test()->item, null, $bucket, $quantity, StockMovementType::Adjustment);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function adjustStockPost(array $overrides = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->manager)->post(
        route('admin.inventory.stock.adjustments.store', test()->item->public_id),
        ['kind' => 'receive', 'quantity' => 10, 'reason' => 'Received against delivery note 4471', ...$overrides],
    );
}

describe('every kind moves exactly the buckets it names', function () {
    it('records the change, links it both ways to its movement, and audits it', function (
        string $kind, ?StockBucket $seedBucket, int $seed, int $quantity, array $expected,
    ) {
        if ($seedBucket !== null) {
            adjustStockSeed($seedBucket, $seed);
        }

        adjustStockPost(['kind' => $kind, 'quantity' => $quantity])->assertSessionHasNoErrors();

        $adjustment = StockAdjustment::query()->sole();
        $movement = $adjustment->movement;

        expect($this->item->refresh()->buckets())->toMatchArray($expected)
            ->and($adjustment->kind->value)->toBe($kind)
            ->and($adjustment->quantity)->toBe($quantity)
            ->and($adjustment->actor_id)->toBe($this->manager->id)
            ->and($adjustment->reason)->toBe('Received against delivery note 4471')
            ->and($movement->type)->toBe(StockMovementType::Adjustment)
            ->and($movement->source_type)->toBe('stock_adjustment')
            ->and($movement->source_id)->toBe($adjustment->id)
            ->and($movement->from_bucket)->toBe(StockAdjustmentKind::from($kind)->source())
            ->and($movement->to_bucket)->toBe(StockAdjustmentKind::from($kind)->destination())
            ->and($movement->actor_id)->toBe($this->manager->id)
            ->and(AuditLog::query()->where('action', 'inventory.stock_adjusted')->where('reason', 'Received against delivery note 4471')->exists())->toBeTrue();
    })->with([
        'receive' => ['receive', null, 0, 10, ['available' => 10]],
        'remove' => ['remove', StockBucket::Available, 10, 3, ['available' => 7]],
        'damage' => ['damage', StockBucket::Available, 10, 2, ['available' => 8, 'damaged' => 2]],
        'repair' => ['repair', StockBucket::Damaged, 4, 1, ['available' => 1, 'damaged' => 3]],
        'write off damaged' => ['write_off_damaged', StockBucket::Damaged, 4, 4, ['damaged' => 0]],
        'restock a return' => ['restock_return', StockBucket::Returned, 2, 2, ['returned' => 0, 'available' => 2]],
        'reject a return' => ['reject_return', StockBucket::Returned, 2, 1, ['returned' => 1, 'damaged' => 1]],
    ]);
});

describe('refusals', function () {
    it('refuses taking more than the bucket holds, naming what it holds, and records nothing', function () {
        adjustStockSeed(StockBucket::Available, 2);

        adjustStockPost(['kind' => 'remove', 'quantity' => 5])
            ->assertSessionHasErrors(['quantity' => __('inventory.refused.insufficient', [
                'bucket' => __('inventory.buckets.available'), 'held' => 2, 'requested' => 5,
            ])]);

        expect($this->item->refresh()->available)->toBe(2)
            ->and(StockAdjustment::query()->count())->toBe(0)
            ->and(StockMovement::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'inventory.stock_adjusted')->exists())->toBeFalse();
    });

    it('requires a reason anybody can act on, in the form and in the action', function () {
        adjustStockPost(['reason' => 'counted'])->assertSessionHasErrors('reason');

        expect(fn () => app(AdjustStock::class)->handle($this->manager, $this->item, StockAdjustmentKind::Receive, 5, '   too short'))
            ->toThrow(InvalidArgumentException::class);

        expect($this->item->refresh()->available)->toBe(0);
    });

    it('refuses a kind a person may not choose, such as reserving', function () {
        adjustStockPost(['kind' => 'reserve'])->assertSessionHasErrors('kind');
        adjustStockPost(['quantity' => 0])->assertSessionHasErrors('quantity');

        expect(StockAdjustment::query()->count())->toBe(0);
    });
});

describe('only an authorised person adjusts central stock (§19)', function () {
    it('refuses a partner holding inventory permissions and staff who may only view, and changes nothing', function (Closure $identity) {
        adjustStockPost([], $identity())->assertForbidden();

        expect($this->item->refresh()->available)->toBe(0)
            ->and(StockAdjustment::query()->count())->toBe(0);
    })->with([
        'a partner holding inventory permissions' => fn () => tap(
            testBusinessAccount(AccountStatus::Active)->owner,
            fn (User $owner) => $owner->givePermissionTo(['inventory.view', 'inventory.edit']),
        ),
        'staff who may only view' => fn () => testPlatformStaff(PlatformRole::ProductManager),
    ]);

    it('refuses a caller that skipped the controller', function () {
        expect(fn () => app(AdjustStock::class)->handle(
            testPlatformStaff(PlatformRole::ProductManager),
            $this->item,
            StockAdjustmentKind::Receive,
            5,
            'Received against delivery note 4471',
        ))->toThrow(AuthorizationException::class);
    });

    it('offers the adjustment on the history screen only to those who may make it', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.adjust', true)
                ->has('adjustment_kinds', 7)
                ->where('adjustment_kinds.0.value', 'receive')
                ->where('adjustment_kinds.0.from', null)
                ->where('adjustment_kinds.0.to', 'available'));

        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.adjust', false));
    });
});

describe('the database', function () {
    beforeEach(function () {
        adjustStockPost();
        $this->adjustment = StockAdjustment::query()->sole();
    });

    it('never edits an adjustment', function () {
        expect(fn () => DB::table('stock_adjustments')->where('id', $this->adjustment->id)->update(['quantity' => 99]))
            ->toThrow(QueryException::class);
    });

    it('never removes an adjustment', function () {
        expect(fn () => DB::table('stock_adjustments')->where('id', $this->adjustment->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses an adjustment written without a reason', function () {
        $movement = app(StockLedger::class)->move($this->item, null, StockBucket::Available, 1, StockMovementType::Adjustment);

        expect(fn () => DB::table('stock_adjustments')->insert([
            'public_id' => (string) Str::ulid(),
            'stock_item_id' => $this->item->id,
            'stock_movement_id' => $movement->id,
            'kind' => 'receive',
            'quantity' => 1,
            'reason' => '    ',
            'actor_id' => $this->manager->id,
        ]))->toThrow(QueryException::class, 'stock_adjustments_reason_given');
    });
});
