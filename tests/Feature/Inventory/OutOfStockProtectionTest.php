<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Actions\TransitionProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\ManageWarehouses;
use App\Domain\Inventory\Actions\SyncProductStockStatus;
use App\Domain\Inventory\Actions\TrackStock;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Out-of-stock protection and overselling prevention (P3-28, §19).
 *
 * A product whose central stock runs out is taken off sale — partner catalogues
 * only offer Active products — and put back when stock returns, but only when the
 * system is what took it off: a person's decision is never reversed, a product
 * whose stock is not tracked is never touched, and nothing but Active and Out of
 * Stock ever moves. And the last unit is never sold twice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->productManager = testPlatformStaff(PlatformRole::ProductManager);
    $this->inventoryManager = testPlatformStaff(PlatformRole::InventoryManager);

    $this->category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create([
        'name' => 'Kettle',
        'sku' => 'FW-KT',
        'category_id' => $this->category->id,
        'wholesale_price_minor' => 120000,
        'status' => ProductStatus::Active,
    ]);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->kettle->id]);

    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 2, StockMovementType::Adjustment);

    $this->reservations = app(StockReservations::class);
});

function outOfStockLastChange(Product $product): ?ProductStatusChange
{
    return ProductStatusChange::query()->where('product_id', $product->id)->orderByDesc('id')->first();
}

describe('out-of-stock protection', function () {
    it('takes a product off sale when its last unit is reserved, and puts it back when the unit returns', function () {
        $reservation = $this->reservations->reserve($this->kettle, null, 2, ReservationKind::OnlinePayment, 'ORD-1');

        expect($this->kettle->refresh()->status)->toBe(ProductStatus::OutOfStock)
            ->and($this->kettle->status->isSellable())->toBeFalse()
            ->and(outOfStockLastChange($this->kettle)->actor_id)->toBeNull()
            ->and(outOfStockLastChange($this->kettle)->reason)->toBe(SyncProductStockStatus::RAN_OUT);

        $this->reservations->release($reservation, 'Payment failed');

        expect($this->kettle->refresh()->status)->toBe(ProductStatus::Active)
            ->and(outOfStockLastChange($this->kettle)->reason)->toBe(SyncProductStockStatus::RESTOCKED);
    });

    it('never puts back on sale a product a person took off sale, however much stock arrives', function () {
        app(TransitionProduct::class)->handle($this->productManager, $this->kettle, ProductStatus::OutOfStock);

        app(AdjustStock::class)->handle($this->inventoryManager, $this->item, StockAdjustmentKind::Receive, 50, 'Received against delivery note 4471');

        expect($this->kettle->refresh()->status)->toBe(ProductStatus::OutOfStock)
            ->and(outOfStockLastChange($this->kettle)->actor_id)->toBe($this->productManager->id);
    });

    it('leaves a product whose stock is not tracked, and a product that is not on sale, exactly as they are', function () {
        $untracked = Product::create(['name' => 'Toaster', 'sku' => 'FW-TS', 'category_id' => $this->category->id, 'status' => ProductStatus::Active]);
        $draft = Product::create(['name' => 'Blender', 'sku' => 'FW-BL', 'category_id' => $this->category->id]);

        app(SyncProductStockStatus::class)->handle($untracked->id);
        app(TrackStock::class)->handle($this->inventoryManager, $this->warehouse, $draft);

        expect($untracked->refresh()->status)->toBe(ProductStatus::Active)
            ->and($draft->refresh()->status)->toBe(ProductStatus::Draft)
            ->and(ProductStatusChange::query()->whereIn('product_id', [$untracked->id, $draft->id])->exists())->toBeFalse();
    });

    it('takes a product straight back off sale when it is activated with none of its tracked stock available', function () {
        $blender = Product::create(['name' => 'Blender', 'sku' => 'FW-BL', 'category_id' => $this->category->id, 'wholesale_price_minor' => 90000]);
        app(TrackStock::class)->handle($this->inventoryManager, $this->warehouse, $blender);

        app(TransitionProduct::class)->handle($this->productManager, $blender, ProductStatus::PendingReview);
        app(TransitionProduct::class)->handle($this->productManager, $blender->refresh(), ProductStatus::Active);

        expect($blender->refresh()->status)->toBe(ProductStatus::OutOfStock)
            ->and($blender->published_at)->not->toBeNull()
            ->and(outOfStockLastChange($blender)->actor_id)->toBeNull();
    });

    it('takes a product off sale when the only warehouse holding its stock is switched off, and back on when it returns', function () {
        $chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram']);
        $held = StockItem::create(['warehouse_id' => $chattogram->id, 'product_id' => $this->kettle->id]);
        app(StockLedger::class)->move($held, null, StockBucket::Available, 5, StockMovementType::Adjustment);
        app(StockLedger::class)->move($this->item, StockBucket::Available, null, 2, StockMovementType::Adjustment);

        $warehouses = app(ManageWarehouses::class);

        $warehouses->update($this->inventoryManager, $chattogram, ['name' => 'Chattogram', 'is_active' => false]);
        expect($this->kettle->refresh()->status)->toBe(ProductStatus::OutOfStock);

        $warehouses->update($this->inventoryManager, $chattogram->refresh(), ['name' => 'Chattogram', 'is_active' => true]);
        expect($this->kettle->refresh()->status)->toBe(ProductStatus::Active);
    });

    it('says why in the reader\'s language on the product editor', function () {
        $this->reservations->reserve($this->kettle, null, 2, ReservationKind::OnlinePayment, 'ORD-2');

        $this->actingAs($this->productManager)
            ->get(route('admin.catalog.products.edit', $this->kettle->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.0.actor', null)
                ->where('history.0.to', 'out_of_stock')
                ->where('history.0.reason', __('inventory.status_sync.ran_out', [], 'en')));

        $this->productManager->forceFill(['locale' => 'bn'])->save();

        $this->actingAs($this->productManager)
            ->get(route('admin.catalog.products.edit', $this->kettle->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('history.0.reason', __('inventory.status_sync.ran_out', [], 'bn')));
    });
});

describe('overselling prevention', function () {
    it('never sells the last unit twice, by reservation or by hand', function () {
        app(StockLedger::class)->move($this->item, StockBucket::Available, null, 1, StockMovementType::Adjustment);

        $this->reservations->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-A');

        expect(fn () => $this->reservations->reserve($this->kettle, null, 1, ReservationKind::CashOnDelivery, 'ORD-B'))
            ->toThrow(InventoryRefused::class);

        expect(fn () => app(AdjustStock::class)->handle($this->inventoryManager, $this->item, StockAdjustmentKind::Remove, 1, 'Counted short at the shelf'))
            ->toThrow(InventoryRefused::class);

        expect($this->item->refresh()->buckets())->toMatchArray(['available' => 0, 'reserved' => 1]);
    });
});
