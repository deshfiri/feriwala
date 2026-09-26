<?php

use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\Supplier;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Supplier portal's landing screen (D25, P13-1) -- the operational
 * snapshot cards added alongside the shell's visual redesign.
 *
 * Every figure is scoped by this Supplier's own supplier_id (§31.3); the
 * active-listings fixture below always creates a second, unrelated
 * Supplier's listing too, so a count that leaked across suppliers would
 * fail this test. withdrawals_pending is the identical query shape scoped
 * the same way, only proven present and zero here -- constructing a real
 * withdrawal exercises wallet funding and payout methods, already covered
 * end-to-end in SupplierWalletTest.
 */
it('shows no snapshot while the supplier is not yet operational', function () {
    $supplier = Supplier::factory()->draft()->create();

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('supplier/dashboard')
            ->where('isOperational', false)
            ->where('snapshot', null),
        );
});

it('counts only this supplier\'s own active listings', function () {
    $supplier = Supplier::factory()->create();
    $stranger = Supplier::factory()->create();

    supplierTestListing($supplier, status: ListingStatus::Approved);
    supplierTestListing($supplier, status: ListingStatus::PartiallyApproved);
    supplierTestListing($supplier, status: ListingStatus::UnderReview);
    supplierTestListing($stranger, status: ListingStatus::Approved);

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('supplier/dashboard')
            ->where('isOperational', true)
            ->where('snapshot.active_listings', 2)
            ->where('snapshot.withdrawals_pending', 0),
        );
});
