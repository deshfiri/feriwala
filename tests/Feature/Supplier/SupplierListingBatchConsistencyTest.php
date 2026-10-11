<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A submitted batch reaches staff as one batch, and is reviewed and submitted as
 * one: its entries never show up as scattered single listings.
 */

function batchConsistencyFixture(): array
{
    $supplier = Supplier::factory()->create();
    $lot = $supplier->lots()->create(['status' => LotStatus::UnderReview, 'submitted_at' => now()]);
    $inLot = $lot->items()->create([
        'supplier_id' => $supplier->id,
        'product_name' => 'Batch panjabi',
        'category_suggestion' => 'Menswear',
        'status' => ListingStatus::UnderReview,
        'submitted_at' => now(),
    ]);
    $single = $supplier->listings()->create([
        'product_name' => 'Single shirt',
        'category_suggestion' => 'Menswear',
        'status' => ListingStatus::UnderReview,
        'submitted_at' => now(),
    ]);

    return [$supplier, $lot, $inLot, $single];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->reviewer = testPlatformStaff(PlatformRole::SupplierManager);
});

it('keeps batch entries out of the single listing queue and lists the batch once', function () {
    [, $lot, , $single] = batchConsistencyFixture();

    $this->actingAs($this->reviewer)->get(route('admin.supplier-listings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('listings.data', 1)
            ->where('listings.data.0.id', $single->public_id));

    $this->get(route('admin.supplier-listing-lots.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('lots.data', 1)
            ->where('lots.data.0.id', $lot->public_id)
            ->where('lots.data.0.items_count', 1));
});

it('sends a batch entry to its batch instead of reviewing it alone', function () {
    [, $lot, $inLot] = batchConsistencyFixture();

    $this->actingAs($this->reviewer)->get(route('admin.supplier-listings.show', $inLot))
        ->assertRedirect(route('admin.supplier-listing-lots.show', $lot->public_id));

    $this->post(route('admin.supplier-listings.decision.store', $inLot), ['reason' => 'x'])
        ->assertSessionHasErrors('listing');
});

it('refuses a supplier submitting one batch entry on its own', function () {
    $supplier = Supplier::factory()->create();
    $lot = $supplier->lots()->create(['status' => LotStatus::Draft]);
    $entry = $lot->items()->create([
        'supplier_id' => $supplier->id,
        'product_name' => 'Batch panjabi',
        'category_suggestion' => 'Menswear',
        'status' => ListingStatus::Draft,
    ]);

    $this->actingAs($supplier, 'supplier')
        ->post(route('supplier.listings.submission.store', $entry->public_id))
        ->assertSessionHasErrors('listing');

    expect($entry->fresh()->status)->toBe(ListingStatus::Draft);
});
