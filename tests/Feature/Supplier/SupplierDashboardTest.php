<?php

use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierWalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
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

it('counts only this supplier\'s own pending listings, not an approved or a stranger\'s', function () {
    $supplier = Supplier::factory()->create();
    $stranger = Supplier::factory()->create();

    supplierTestListing($supplier, status: ListingStatus::Submitted);
    supplierTestListing($supplier, status: ListingStatus::UnderReview);
    supplierTestListing($supplier, status: ListingStatus::CorrectionRequired);
    supplierTestListing($supplier, status: ListingStatus::Approved);
    supplierTestListing($stranger, status: ListingStatus::Submitted);

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.pending_listings', 3),
        );
});

it('counts this supplier\'s own active offers and sums their available stock', function () {
    $supplier = Supplier::factory()->create();
    $stranger = Supplier::factory()->create();

    supplierTestOffer($supplier); // stock quantity 10
    supplierTestOffer($supplier); // stock quantity 10
    supplierTestOffer($stranger); // must not count

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.active_offers', 2)
            ->where('snapshot.stock_available', 20),
        );
});

it('shows zeroed payable totals and a zeroed wallet when there is nothing yet', function () {
    $supplier = Supplier::factory()->create();

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.payables.pending.amount', '0.00')
            ->where('snapshot.payables.eligible.amount', '0.00')
            ->where('snapshot.payables.settled.amount', '0.00')
            ->where('snapshot.wallet', null),
        );
});

it('shows the available, reserved and recovery wallet balances, not just one figure', function () {
    $supplier = Supplier::factory()->create();
    $wallet = app(OpenSupplierWallet::class)->handle($supplier, Currency::BDT);
    app(SupplierWalletService::class)->credit(
        $wallet,
        Money::fromDecimal('5000.00', Currency::BDT),
        new SupplierPostingContext(source: 'payable_settlement', description: 'Fixture'),
    );

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.wallet.total.amount', '5000.00')
            ->where('snapshot.wallet.available.amount', '5000.00')
            ->where('snapshot.wallet.reserved.amount', '0.00')
            ->where('snapshot.wallet.recovery.amount', '0.00'),
        );
});
