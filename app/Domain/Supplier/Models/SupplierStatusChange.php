<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a Supplier's status (D25, P0-16-equivalent shape).
 * Append-only.
 *
 * @property int $id
 * @property int $supplier_id
 * @property SupplierStatus|null $previous_status
 * @property SupplierStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property SupplierStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read Supplier $supplier
 * @property-read User|null $changedBy
 */
class SupplierStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'supplier_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => SupplierStatus::class,
            'new_status' => SupplierStatus::class,
            'source' => SupplierStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
