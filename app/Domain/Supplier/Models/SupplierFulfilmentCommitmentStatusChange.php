<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a fulfilment commitment's status (Supplier Bulk
 * Product Listing batch, same shape as
 * {@see SupplierProductListingLotStatusChange}). Append-only.
 *
 * @property int $id
 * @property int $supplier_fulfilment_commitment_id
 * @property FulfilmentCommitmentStatus|null $previous_status
 * @property FulfilmentCommitmentStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property SupplierStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class SupplierFulfilmentCommitmentStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'supplier_fulfilment_commitment_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => FulfilmentCommitmentStatus::class,
            'new_status' => FulfilmentCommitmentStatus::class,
            'source' => SupplierStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierFulfilmentCommitment, $this>
     */
    public function commitment(): BelongsTo
    {
        return $this->belongsTo(SupplierFulfilmentCommitment::class, 'supplier_fulfilment_commitment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
