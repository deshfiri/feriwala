<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a Supplier payable's status (D25, P13-22).
 * Append-only, in the shared status-history shape.
 *
 * @property int $id
 * @property int $supplier_payable_id
 * @property PayableStatus|null $previous_status
 * @property PayableStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property PayableChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read SupplierPayable $payable
 * @property-read User|null $changedBy
 */
class SupplierPayableStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'supplier_payable_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => PayableStatus::class,
            'new_status' => PayableStatus::class,
            'source' => PayableChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierPayable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(SupplierPayable::class, 'supplier_payable_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
