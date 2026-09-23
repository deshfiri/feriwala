<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Supplier\Enums\SupplierWithdrawalChangeSource;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded move in a Supplier withdrawal's lifecycle (D25, P13-24).
 *
 * @property int $id
 * @property int $supplier_withdrawal_id
 * @property SupplierWithdrawalStatus|null $previous_status
 * @property SupplierWithdrawalStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property SupplierWithdrawalChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class SupplierWithdrawalStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'supplier_withdrawal_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => SupplierWithdrawalStatus::class,
            'new_status' => SupplierWithdrawalStatus::class,
            'source' => SupplierWithdrawalChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierWithdrawal, $this>
     */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(SupplierWithdrawal::class, 'supplier_withdrawal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
