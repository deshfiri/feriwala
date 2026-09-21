<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a listing's status (D25, P0-16-equivalent shape).
 * Append-only.
 *
 * @property int $id
 * @property int $supplier_product_listing_id
 * @property ListingStatus|null $previous_status
 * @property ListingStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property SupplierStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class SupplierProductListingStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'supplier_product_listing_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => ListingStatus::class,
            'new_status' => ListingStatus::class,
            'source' => SupplierStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierProductListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListing::class, 'supplier_product_listing_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
