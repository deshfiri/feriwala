<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->supplier = Supplier::factory()->create();
    $this->offer = supplierTestOffer($this->supplier);
    $this->manager = testPlatformStaff(PlatformRole::SupplierManager);
});

test('a supplier submits an availability update and nothing moves until staff approves it', function () {
    supplierTestSignIn($this->supplier);

    $this->post(route('supplier.stock.updates.store', $this->offer), ['quantity' => 75, 'note' => 'New batch'])
        ->assertSessionHasNoErrors();

    $update = SupplierStockUpdate::query()->firstOrFail();

    expect($update->status)->toBe(StockUpdateStatus::Pending)
        ->and($update->requested_quantity)->toBe(75)
        ->and($this->offer->stock->refresh()->quantity)->toBe(10)
        ->and(SupplierStockMovement::query()->count())->toBe(0);

    $this->get(route('supplier.stock.index'))->assertInertia(fn (Assert $page) => $page
        ->component('supplier/stock/index')
        ->where('offers.0.available_quantity', 10)
        ->where('offers.0.pending_update', true));
});

test('a negative or missing quantity is rejected', function (array $payload) {
    supplierTestSignIn($this->supplier);

    $this->post(route('supplier.stock.updates.store', $this->offer), $payload)->assertSessionHasErrors('quantity');

    expect(SupplierStockUpdate::query()->count())->toBe(0);
})->with([
    'negative' => [['quantity' => -1]],
    'missing' => [[]],
    'not a number' => [['quantity' => 'lots']],
]);

test('a supplier cannot submit availability for, or see, another supplier\'s offer', function () {
    $theirs = supplierTestOffer(Supplier::factory()->create());
    supplierTestSignIn($this->supplier);

    $this->post(route('supplier.stock.updates.store', $theirs), ['quantity' => 5])->assertNotFound();

    $this->get(route('supplier.stock.index'))
        ->assertInertia(fn (Assert $page) => $page->has('offers', 1)->where('offers.0.id', $this->offer->public_id));

    expect(SupplierStockUpdate::query()->count())->toBe(0);
});

test('a supplier that is not approved cannot reach availability at all', function () {
    supplierTestSignIn(Supplier::factory()->suspended()->create());

    $this->get(route('supplier.stock.index'))->assertForbidden();
});

test('staff approval applies the quantity, records an immutable movement and an audit row', function () {
    $update = $this->offer->stockUpdates()->create(['requested_quantity' => 75, 'status' => StockUpdateStatus::Pending]);

    $this->actingAs($this->manager)->post(route('admin.supplier-stock.decision.store', $update), ['decision' => 'approve', 'note' => 'Counted.'])
        ->assertSessionHasNoErrors();

    $movement = SupplierStockMovement::query()->firstOrFail();

    expect($update->refresh()->status)->toBe(StockUpdateStatus::Approved)
        ->and($update->decided_by)->toBe($this->manager->id)
        ->and($this->offer->stock->refresh()->quantity)->toBe(75)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_after)->toBe(75)
        ->and($movement->delta())->toBe(65)
        ->and($movement->source)->toBe('supplier_update_approved')
        ->and(AuditLog::query()->where('action', 'supplier_stock.approved')->count())->toBe(1);

    expect(fn () => SupplierStockMovement::query()->whereKey($movement->id)->update(['quantity_after' => 1]))
        ->toThrow(QueryException::class);
    expect(fn () => SupplierStockMovement::query()->whereKey($movement->id)->delete())
        ->toThrow(QueryException::class);
});

test('a rejected update leaves the quantity alone and a decided update cannot be decided again', function () {
    $update = $this->offer->stockUpdates()->create(['requested_quantity' => 75, 'status' => StockUpdateStatus::Pending]);

    $this->actingAs($this->manager)->post(route('admin.supplier-stock.decision.store', $update), ['decision' => 'reject', 'note' => 'Not credible.']);

    expect($update->refresh()->status)->toBe(StockUpdateStatus::Rejected)
        ->and($this->offer->stock->refresh()->quantity)->toBe(10);

    $this->post(route('admin.supplier-stock.decision.store', $update), ['decision' => 'approve'])->assertSessionHasErrors('decision');
    expect($this->offer->stock->refresh()->quantity)->toBe(10);
});

test('staff adjust availability directly with a reason and never to a negative number', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.supplier-offers.stock-adjustment.store', $this->offer), ['quantity' => 3, 'reason' => 'Stock count.'])
        ->assertSessionHasNoErrors();

    expect($this->offer->stock->refresh()->quantity)->toBe(3)
        ->and(SupplierStockMovement::query()->firstOrFail()->source)->toBe('staff_adjustment');

    $this->post(route('admin.supplier-offers.stock-adjustment.store', $this->offer), ['quantity' => -4, 'reason' => 'Bad.'])
        ->assertSessionHasErrors('quantity');
    $this->post(route('admin.supplier-offers.stock-adjustment.store', $this->offer), ['quantity' => 4, 'reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->offer->stock->refresh()->quantity)->toBe(3);
});

test('the database refuses negative supplier stock', function () {
    expect(fn () => SupplierOfferStock::query()->where('supplier_offer_id', $this->offer->id)->update(['quantity' => -1]))
        ->toThrow(QueryException::class);
});

test('stock decisions need supplier_stock.edit and viewing needs supplier_stock.view', function () {
    $update = $this->offer->stockUpdates()->create(['requested_quantity' => 75, 'status' => StockUpdateStatus::Pending]);

    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo('supplier_stock.view');

    $this->actingAs($viewer)->get(route('admin.supplier-stock.index'))->assertOk();
    $this->post(route('admin.supplier-stock.decision.store', $update), ['decision' => 'approve'])->assertForbidden();
    $this->post(route('admin.supplier-offers.stock-adjustment.store', $this->offer), ['quantity' => 1, 'reason' => 'x'])->assertForbidden();

    $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))->get(route('admin.supplier-stock.index'))->assertForbidden();

    expect($this->offer->stock->refresh()->quantity)->toBe(10);
});

test('supplier availability never touches central stock and stays a distinguishable source', function () {
    $item = StockItem::query()->count();
    $movements = StockMovement::query()->count();

    $update = $this->offer->stockUpdates()->create(['requested_quantity' => 500, 'status' => StockUpdateStatus::Pending]);
    $this->actingAs($this->manager)->post(route('admin.supplier-stock.decision.store', $update), ['decision' => 'approve']);
    $this->post(route('admin.supplier-offers.stock-adjustment.store', $this->offer), ['quantity' => 20, 'reason' => 'Recount.']);

    expect(StockItem::query()->count())->toBe($item)
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and(SupplierStockMovement::query()->count())->toBe(2)
        ->and((new SupplierOfferStock)->getTable())->not->toBe((new StockItem)->getTable());
});
