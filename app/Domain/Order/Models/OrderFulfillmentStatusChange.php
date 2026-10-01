<?php

namespace App\Domain\Order\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to an order's fulfilment status. Append-only.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderFulfillmentStatus|null $previous_status
 * @property OrderFulfillmentStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property OrderStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read Order $order
 * @property-read User|null $changedBy
 */
class OrderFulfillmentStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'order_fulfillment_status_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => OrderFulfillmentStatus::class,
            'new_status' => OrderFulfillmentStatus::class,
            'source' => OrderStatusChangeSource::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
