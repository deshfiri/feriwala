<?php

use App\Domain\Supplier\Actions\ArchiveSupplierListingLotDraft;
use App\Domain\Supplier\Actions\CreateSupplierListingLot;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListingLotStatusChange;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Postgres aborts the whole transaction on any failed statement, so
 * asserting a QueryException and then touching the database again in the
 * same test cascades into a "transaction is aborted" error unrelated to
 * what's being tested (§ CLAUDE.md "Tests" -- shared-schema gotchas). A
 * `DB::transaction()` wrapper is a savepoint here, since Pest's own
 * RefreshDatabase transaction is already open; only that inner savepoint
 * rolls back on failure, leaving the rest of the test's transaction usable.
 */
function supplierLotSchemaAssertRefused(Closure $callback): void
{
    try {
        DB::transaction($callback);
        test()->fail('Expected a QueryException to be thrown.');
    } catch (QueryException $exception) {
        expect($exception)->toBeInstanceOf(QueryException::class);
    }
}

/*
 * The listing-lot schema and state model (Supplier Bulk Product Listing,
 * commit 1 of 5): a Supplier's draft-then-submit-once batch of product
 * entries, and the widened item-delete guard the batch's spec requires
 * ("Draft or Returned").
 */

it('creates a lot in Draft, owned by the supplier', function () {
    $supplier = Supplier::factory()->create();

    $lot = app(CreateSupplierListingLot::class)->handle($supplier, 'October restock');

    expect($lot->status)->toBe(LotStatus::Draft)
        ->and($lot->supplier_id)->toBe($supplier->id)
        ->and($lot->title)->toBe('October restock')
        ->and($lot->public_id)->not->toBeEmpty()
        ->and($lot->reference)->toStartWith('SLL');
});

describe('LotStatus transitions', function () {
    it('permits exactly the declared edges', function () {
        $expected = [
            LotStatus::Draft->value => [LotStatus::Submitted, LotStatus::Closed],
            LotStatus::Submitted->value => [LotStatus::UnderReview],
            LotStatus::UnderReview->value => [LotStatus::Approved, LotStatus::PartiallyApproved, LotStatus::Rejected, LotStatus::Returned],
            LotStatus::Returned->value => [LotStatus::Submitted],
            LotStatus::PartiallyApproved->value => [LotStatus::Closed],
            LotStatus::Approved->value => [LotStatus::Closed],
            LotStatus::Rejected->value => [LotStatus::Closed],
            LotStatus::Closed->value => [],
        ];

        foreach (LotStatus::cases() as $status) {
            expect($status->transitionsTo())->toEqualCanonicalizing($expected[$status->value]);
        }

        expect(LotStatus::Closed->isTerminal())->toBeTrue()
            ->and(LotStatus::Draft->isTerminal())->toBeFalse();
    });

    it('refuses every edge not declared', function () {
        $supplier = Supplier::factory()->create();
        $lot = app(CreateSupplierListingLot::class)->handle($supplier);

        // Draft may only go to Submitted or Closed -- not straight to Approved.
        expect(fn () => $lot->transitionWithHistory(
            LotStatus::Approved,
            new StatusChange(reason: 'Should be refused.'),
            ['source' => SupplierStatusChangeSource::System],
        ))->toThrow(IllegalStateTransition::class);
    });
});

describe('ArchiveSupplierListingLotDraft', function () {
    it('discards an empty draft lot', function () {
        $supplier = Supplier::factory()->create();
        $lot = app(CreateSupplierListingLot::class)->handle($supplier);

        app(ArchiveSupplierListingLotDraft::class)->handle($supplier, $lot);

        expect($lot->fresh()->status)->toBe(LotStatus::Closed);
    });

    it('refuses to discard a lot that still holds an item', function () {
        $supplier = Supplier::factory()->create();
        $lot = app(CreateSupplierListingLot::class)->handle($supplier);
        $supplier->listings()->create(['product_name' => 'A product', 'lot_id' => $lot->id]);

        expect(fn () => app(ArchiveSupplierListingLotDraft::class)->handle($supplier, $lot))
            ->toThrow(InvalidArgumentException::class);

        expect($lot->fresh()->status)->toBe(LotStatus::Draft);
    });

    it('refuses a non-draft lot', function () {
        $supplier = Supplier::factory()->create();
        $lot = app(CreateSupplierListingLot::class)->handle($supplier);
        $lot->transitionWithHistory(LotStatus::Submitted, new StatusChange(reason: 'x'), ['source' => SupplierStatusChangeSource::Supplier]);

        expect(fn () => app(ArchiveSupplierListingLotDraft::class)->handle($supplier, $lot))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses another supplier\'s lot', function () {
        $owner = Supplier::factory()->create();
        $lot = app(CreateSupplierListingLot::class)->handle($owner);
        $stranger = Supplier::factory()->create();

        expect(fn () => app(ArchiveSupplierListingLotDraft::class)->handle($stranger, $lot))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('locks lot_id on a listing once it has been written', function () {
    $supplier = Supplier::factory()->create();
    $lotA = app(CreateSupplierListingLot::class)->handle($supplier);
    $lotB = app(CreateSupplierListingLot::class)->handle($supplier);
    $listing = $supplier->listings()->create(['product_name' => 'A product', 'lot_id' => $lotA->id]);

    supplierLotSchemaAssertRefused(fn () => $listing->forceFill(['lot_id' => $lotB->id])->save());

    // Writing back the same value is not a change and stays allowed.
    $listing->forceFill(['lot_id' => $lotA->id])->save();
    expect($listing->fresh()->lot_id)->toBe($lotA->id);
});

it('rolls a lot up from its items\' statuses', function () {
    $supplier = Supplier::factory()->create();
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);

    // Empty lot: rollup is a no-op, keeps the lot's own current status.
    expect($lot->rollupStatus())->toBe(LotStatus::Draft);

    $a = $supplier->listings()->create(['product_name' => 'A', 'lot_id' => $lot->id, 'status' => ListingStatus::Draft]);
    expect($lot->rollupStatus())->toBe(LotStatus::Draft);

    $a->forceFill(['status' => ListingStatus::UnderReview])->save();
    expect($lot->rollupStatus())->toBe(LotStatus::UnderReview);

    $b = $supplier->listings()->create(['product_name' => 'B', 'lot_id' => $lot->id, 'status' => ListingStatus::UnderReview]);

    // Anything still pending keeps the lot at UnderReview, whatever else is decided.
    $a->forceFill(['status' => ListingStatus::Approved])->save();
    expect($lot->rollupStatus())->toBe(LotStatus::UnderReview);

    $b->forceFill(['status' => ListingStatus::Approved])->save();
    expect($lot->rollupStatus())->toBe(LotStatus::Approved);

    $b->forceFill(['status' => ListingStatus::Rejected])->save();
    expect($lot->rollupStatus())->toBe(LotStatus::PartiallyApproved);

    $a->forceFill(['status' => ListingStatus::Rejected])->save();
    expect($lot->rollupStatus())->toBe(LotStatus::Rejected);

    $a->forceFill(['status' => ListingStatus::CorrectionRequired])->save();
    expect($lot->rollupStatus())->toBe(LotStatus::Returned);
});

describe('widened item-delete guard', function () {
    it('permits delete while the parent listing is Draft or CorrectionRequired', function () {
        $supplier = Supplier::factory()->create();

        foreach ([ListingStatus::Draft, ListingStatus::CorrectionRequired] as $status) {
            $listing = supplierTestListing($supplier, status: $status);
            $item = $listing->items()->firstOrFail();

            $item->delete();

            expect($listing->items()->count())->toBe(0);
        }
    });

    it('refuses delete for every other listing status', function () {
        $supplier = Supplier::factory()->create();

        foreach (ListingStatus::cases() as $status) {
            if (in_array($status, [ListingStatus::Draft, ListingStatus::CorrectionRequired], true)) {
                continue;
            }

            $listing = supplierTestListing($supplier, status: $status);
            $item = $listing->items()->firstOrFail();

            supplierLotSchemaAssertRefused(fn () => $item->delete());
        }
    });
});

it('keeps the lot status history append-only', function () {
    $supplier = Supplier::factory()->create();
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);
    $lot->transitionWithHistory(LotStatus::Submitted, new StatusChange(reason: 'x'), ['source' => SupplierStatusChangeSource::Supplier]);

    $entry = SupplierProductListingLotStatusChange::query()->firstOrFail();

    expect(fn () => $entry->update(['reason' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});
