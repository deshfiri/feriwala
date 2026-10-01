<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a listing lot's status (Supplier Bulk Product
 * Listing batch, same shape as {@see SupplierProductListingStatusChange}).
 * Append-only.
 *
 * @property int $id
 * @property int $supplier_product_listing_lot_id
 * @property LotStatus|null $previous_status
 * @property LotStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property SupplierStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class SupplierProductListingLotStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'supplier_product_listing_lot_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => LotStatus::class,
            'new_status' => LotStatus::class,
            'source' => SupplierStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierProductListingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListingLot::class, 'supplier_product_listing_lot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
