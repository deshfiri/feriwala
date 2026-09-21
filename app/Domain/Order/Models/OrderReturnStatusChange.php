<?php

namespace App\Domain\Order\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a return's status (§18.3, P0-16, P6-12). Append-only.
 *
 * The same shared shape every other history in the system uses, so a return's
 * story reads like an order's. The internal note is for the people running the
 * platform; only the public note reaches the partner and their customer.
 *
 * @property int $id
 * @property int $order_return_id
 * @property ReturnStatus|null $previous_status
 * @property ReturnStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property OrderStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read OrderReturn $orderReturn
 * @property-read User|null $changedBy
 */
class OrderReturnStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'order_return_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => ReturnStatus::class,
            'new_status' => ReturnStatus::class,
            'source' => OrderStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
