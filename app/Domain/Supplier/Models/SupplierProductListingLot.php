<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Models\User;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Supplier's draft-then-submit-once batch of product entries (Supplier
 * Bulk Product Listing).
 *
 * A lot never carries its own product data -- every product entry is an
 * ordinary {@see SupplierProductListing} row, unchanged in shape, now
 * pointed at this lot via `lot_id`. This table exists purely to group them
 * for one submission and to hold the status a whole batch is at, which is
 * always {@see rollupStatus()}, never set directly except for the two
 * Supplier-driven moves an empty draft can make on its own (submit, discard).
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $supplier_id
 * @property LotStatus $status
 * @property string|null $title
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property-read Supplier $supplier
 * @property-read User|null $reviewedBy
 * @property-read Collection<int, SupplierProductListing> $items
 * @property-read Collection<int, SupplierProductListingLotStatusChange> $statusHistory
 */
class SupplierProductListingLot extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    protected $attributes = [
        'status' => LotStatus::Draft->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LotStatus::class,
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::SupplierListingLot;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The product entries drafted into this lot -- the batch's own "item".
     *
     * @return HasMany<SupplierProductListing, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierProductListing::class, 'lot_id')->orderBy('id');
    }

    /**
     * @return HasMany<SupplierProductListingLotStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupplierProductListingLotStatusChange::class)->orderBy('id');
    }

    /**
     * What this lot's status should be, purely from its items' current
     * {@see ListingStatus} values -- the same rollup shape
     * `DecideSupplierListing` already computes one level down from item
     * statuses, applied one level up. Pure: no side effects, so the actions
     * that actually persist a transition (`SubmitSupplierListingLot`,
     * `DecideSupplierListingLot`) can call it freely before deciding what to
     * write.
     */
    public function rollupStatus(): LotStatus
    {
        $statuses = $this->items()->pluck('status');

        if ($statuses->isEmpty()) {
            return $this->status;
        }

        // An item stays Draft only while this lot itself is still Draft
        // (new items may only be added to a Draft lot), and an Archived one
        // was withdrawn by the Supplier before ever being decided --
        // neither contributes to a review-derived status.
        $reviewable = $statuses->reject(fn (ListingStatus $status) => in_array(
            $status, [ListingStatus::Draft, ListingStatus::Archived], true,
        ));

        if ($reviewable->isEmpty()) {
            return $this->status === LotStatus::Draft ? LotStatus::Draft : LotStatus::Closed;
        }

        $pending = $reviewable->filter(fn (ListingStatus $s) => $s === ListingStatus::UnderReview)->count();
        $approved = $reviewable->filter(fn (ListingStatus $s) => $s === ListingStatus::Approved)->count();
        $rejected = $reviewable->filter(fn (ListingStatus $s) => $s === ListingStatus::Rejected)->count();
        $correction = $reviewable->filter(fn (ListingStatus $s) => $s === ListingStatus::CorrectionRequired)->count();

        // Anything still awaiting a decision keeps the lot at UnderReview,
        // whatever else has already been decided in an earlier round --
        // the lot only settles into its final mix once nothing is left
        // pending (§"leave other items pending").
        return match (true) {
            $pending > 0 => LotStatus::UnderReview,
            $correction > 0 => LotStatus::Returned,
            $approved > 0 && $rejected === 0 => LotStatus::Approved,
            $approved > 0 => LotStatus::PartiallyApproved,
            default => LotStatus::Rejected,
        };
    }
}
