<?php

namespace App\Domain\Order\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Order\Enums\OrderNotificationStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to an order's status (§18.3, P6-6). Append-only.
 *
 * The internal note is for the people running the platform; only the public
 * note is ever shown to the account that placed the order.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $previous_status
 * @property OrderStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property OrderStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property OrderNotificationStatus $notification_status
 * @property-read Order $order
 * @property-read User|null $changedBy
 */
class OrderStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'order_status_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => OrderStatus::class,
            'new_status' => OrderStatus::class,
            'source' => OrderStatusChangeSource::class,
            'notification_status' => OrderNotificationStatus::class,
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
