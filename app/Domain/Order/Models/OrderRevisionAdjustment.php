<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Domain\Order\Enums\RevisionAdjustmentKind;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One compensating record an applied revision caused (D38): a payment
 * re-recorded, a balance-due payment, a refund request, a reservation released
 * or taken, a payable cancelled or accrued, a ledger entry, an invoice
 * adjustment, a courier follow-up.
 *
 * Points at that record by type and id, and keeps a reference a person can
 * read. The record itself keeps its own state; this row never changes.
 *
 * @property int $id
 * @property int $order_revision_id
 * @property RevisionAdjustmentKind $kind
 * @property string|null $currency_code
 * @property Money|null $amount
 * @property string|null $direction `in` towards the platform, `out` away from it
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $reference
 * @property array<string, mixed> $detail
 * @property CarbonImmutable $created_at
 * @property-read OrderRevision $revision
 */
class OrderRevisionAdjustment extends Model
{
    public const UPDATED_AT = null;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => RevisionAdjustmentKind::class,
            'amount' => MoneyCast::class,
            'detail' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A revision adjustment is a record of a correction and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A revision adjustment is a record of a correction and cannot be removed.'));
    }

    /**
     * @return BelongsTo<OrderRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(OrderRevision::class, 'order_revision_id');
    }
}
