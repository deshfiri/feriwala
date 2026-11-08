<?php

use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * A Supplier's own products and availability over the mobile API (D25, P13-13,
 * P13-15). A Supplier only submits an availability update: nothing moves until
 * staff approve it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->supplier = Supplier::factory()->create();
    $this->offer = supplierTestOffer($this->supplier);
    $this->other = Supplier::factory()->create();
    $this->theirs = supplierTestOffer($this->other);
});

describe('offers', function () {
    test('a supplier lists and opens only its own offers, never the platform rate', function () {
        Sanctum::actingAs($this->supplier);

        $this->getJson(route('mobile.v1.supplier.offers.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->offer->public_id)
            ->assertJsonPath('data.0.supplier_rate.amount', '1000.00')
            ->assertJsonPath('data.0.available_quantity', 10)
            ->assertJsonMissingPath('data.0.platform_rate');

        $this->getJson(route('mobile.v1.supplier.offers.show', $this->offer->public_id))
            ->assertOk()
            ->assertJsonPath('data.id', $this->offer->public_id)
            ->assertJsonMissingPath('data.platform_rate')
            ->assertJsonStructure(['data' => ['rate_history']]);
    });

    test('another supplier\'s offer is a 404', function () {
        Sanctum::actingAs($this->supplier);

        $this->getJson(route('mobile.v1.supplier.offers.show', $this->theirs->public_id))->assertNotFound();
    });

    test('offers need a token and an operational supplier', function () {
        $this->getJson(route('mobile.v1.supplier.offers.index'))->assertUnauthorized();

        Sanctum::actingAs(Supplier::factory()->suspended()->create());

        $this->getJson(route('mobile.v1.supplier.offers.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'supplier_not_operational');
    });
});

describe('stock', function () {
    test('a supplier submits an update and nothing moves until staff approve it', function () {
        Sanctum::actingAs($this->supplier);

        $this->postJson(route('mobile.v1.supplier.stock.updates.store', $this->offer->public_id), ['quantity' => 75, 'note' => 'New batch'])
            ->assertCreated()
            ->assertJsonPath('data.requested_quantity', 75)
            ->assertJsonPath('data.status', StockUpdateStatus::Pending->value);

        expect(SupplierStockUpdate::query()->count())->toBe(1)
            ->and($this->offer->stock->refresh()->quantity)->toBe(10)
            ->and(SupplierStockMovement::query()->count())->toBe(0);

        $this->getJson(route('mobile.v1.supplier.stock.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->offer->public_id)
            ->assertJsonPath('data.0.available_quantity', 10)
            ->assertJsonPath('data.0.pending_update', true)
            ->assertJsonCount(1, 'data.0.recent_updates');
    });

    test('a negative, missing or non-numeric quantity is refused', function (array $payload) {
        Sanctum::actingAs($this->supplier);

        $this->postJson(route('mobile.v1.supplier.stock.updates.store', $this->offer->public_id), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        expect(SupplierStockUpdate::query()->count())->toBe(0);
    })->with([
        'negative' => [['quantity' => -1]],
        'missing' => [[]],
        'not a number' => [['quantity' => 'lots']],
    ]);

    test('a supplier cannot submit availability for another supplier\'s offer', function () {
        Sanctum::actingAs($this->supplier);

        $this->postJson(route('mobile.v1.supplier.stock.updates.store', $this->theirs->public_id), ['quantity' => 5])->assertNotFound();

        expect(SupplierStockUpdate::query()->count())->toBe(0);
    });

    test('stock needs a token and an operational supplier', function () {
        $this->getJson(route('mobile.v1.supplier.stock.index'))->assertUnauthorized();
        $this->postJson(route('mobile.v1.supplier.stock.updates.store', $this->offer->public_id), ['quantity' => 5])->assertUnauthorized();

        Sanctum::actingAs(Supplier::factory()->suspended()->create());

        $this->postJson(route('mobile.v1.supplier.stock.updates.store', $this->offer->public_id), ['quantity' => 5])
            ->assertForbidden()
            ->assertJsonPath('code', 'supplier_not_operational');

        expect(SupplierStockUpdate::query()->count())->toBe(0);
    });
});
