<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\DeleteStockItem;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Deleting stock (§19).
 *
 * Movements are append-only, so only a stock row nothing was ever recorded
 * against can be removed. One with a history is part of the ledger and stays.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $category = Category::create(['name' => 'Kitchen']);
    $product = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id]);
});

describe('a stock row that was never used', function () {
    it('is deleted, audited, and can be held again', function () {
        $this->actingAs($this->manager)
            ->delete(route('admin.inventory.stock.destroy', $this->item->public_id))
            ->assertRedirect(route('admin.inventory.stock.index'))
            ->assertSessionHasNoErrors();

        expect(StockItem::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'inventory.stock_deleted')->exists())->toBeTrue();

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), [
                'warehouse' => $this->item->warehouse->public_id,
                'product' => $this->item->product->public_id,
            ])
            ->assertSessionHasNoErrors();

        expect(StockItem::query()->count())->toBe(1);
    });
});

describe('a stock row with a history', function () {
    it('is refused and left untouched', function () {
        app(StockLedger::class)->move($this->item, null, StockBucket::Available, 5, StockMovementType::Adjustment);

        $this->actingAs($this->manager)
            ->delete(route('admin.inventory.stock.destroy', $this->item->public_id))
            ->assertSessionHasErrors(['item' => __('inventory.refused.stock_has_history')]);

        expect($this->item->refresh()->available)->toBe(5)
            ->and(AuditLog::query()->where('action', 'inventory.stock_deleted')->exists())->toBeFalse();
    });

    it('is offered for deletion on the history screen only while it has none', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('deletable', true)->where('can.delete', true));

        app(StockLedger::class)->move($this->item, null, StockBucket::Available, 5, StockMovementType::Adjustment);

        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('deletable', false));
    });
});

describe('stock of a deleted product', function () {
    it('is deleted whatever its history, which stays on record', function () {
        app(StockLedger::class)->move($this->item, null, StockBucket::Available, 5, StockMovementType::Adjustment);
        $this->item->product->delete();

        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.show', $this->item->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('deletable', true));

        $this->actingAs($this->manager)
            ->delete(route('admin.inventory.stock.destroy', $this->item->public_id))
            ->assertRedirect(route('admin.inventory.stock.index'))
            ->assertSessionHasNoErrors();

        expect(StockItem::query()->count())->toBe(0)
            ->and(StockItem::withTrashed()->count())->toBe(1)
            ->and(StockMovement::query()->where('stock_item_id', $this->item->id)->count())->toBe(1);

        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.index'))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    });
});

describe('only an authorised person deletes stock (§19)', function () {
    it('refuses a partner holding inventory permissions and staff who may only view', function (Closure $identity) {
        $this->actingAs($identity())
            ->delete(route('admin.inventory.stock.destroy', $this->item->public_id))
            ->assertForbidden();

        expect(StockItem::query()->count())->toBe(1);
    })->with([
        'a partner holding inventory permissions' => fn () => tap(
            testBusinessAccount(AccountStatus::Active)->owner,
            fn (User $owner) => $owner->givePermissionTo(['inventory.view', 'inventory.edit']),
        ),
        'staff who may only view' => fn () => testPlatformStaff(PlatformRole::ProductManager),
    ]);

    it('refuses a caller that skipped the controller', function () {
        expect(fn () => app(DeleteStockItem::class)->handle(
            testPlatformStaff(PlatformRole::ProductManager),
            $this->item,
        ))->toThrow(AuthorizationException::class);

        expect(StockItem::query()->count())->toBe(1);
    });
});
