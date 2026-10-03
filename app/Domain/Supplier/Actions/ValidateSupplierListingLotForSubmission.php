<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use Illuminate\Support\Collection;

/**
 * Checks every submittable product entry in a lot before any of them is
 * actually submitted (Supplier Bulk Product Listing batch).
 *
 * Pure and read-only: it never writes anything, so it is safe to call both
 * from the controller (to hand a 422 with item-keyed errors straight back to
 * the workspace) and again from inside {@see SubmitSupplierListingLot}'s own
 * transaction as the final guard against a race between the two reads.
 *
 * Most of a listing item's own rules are already database CHECK constraints
 * (rate/quantity non-negative, a supply mode's own required fields) and
 * cannot be violated by the time a row exists at all. The one rule that
 * cannot be a CHECK constraint -- "this listing has at least one primary
 * image" is an aggregate over a *different* table -- is exactly what this
 * class exists to enforce before submission, per the batch's own
 * requirement that a partial unique index alone is not sufficient.
 */
class ValidateSupplierListingLotForSubmission
{
    /**
     * The product entries this lot would actually submit -- currently
     * `Draft` or `CorrectionRequired`; anything already under review or
     * decided is untouched by a (re)submission and is never validated here.
     *
     * @return Collection<int, SupplierProductListing>
     */
    public function submittableItems(SupplierProductListingLot $lot): Collection
    {
        return $lot->items()
            ->whereIn('status', [ListingStatus::Draft->value, ListingStatus::CorrectionRequired->value])
            ->with(['items', 'media'])
            ->get();
    }

    /**
     * @return array<string, list<string>> listing public_id => error messages
     */
    public function handle(SupplierProductListingLot $lot): array
    {
        $errors = [];

        $items = $this->submittableItems($lot);

        if ($items->isEmpty()) {
            return ['lot' => ['There is nothing left to submit in this lot.']];
        }

        foreach ($items as $entry) {
            $entryErrors = [];

            if (trim((string) $entry->product_name) === '') {
                $entryErrors[] = 'A product title is required.';
            }

            if ($entry->category_id === null && trim((string) $entry->category_suggestion) === '') {
                $entryErrors[] = 'Choose an existing category or suggest one.';
            }

            if ($entry->items->isEmpty()) {
                $entryErrors[] = 'At least one Supplier rate/BPC entry is required.';
            }

            if ($entry->primaryMedia() === null) {
                $entryErrors[] = 'A primary image is required before this entry can be submitted.';
            }

            if ($entryErrors !== []) {
                $errors[$entry->public_id] = $entryErrors;
            }
        }

        return $errors;
    }
}
