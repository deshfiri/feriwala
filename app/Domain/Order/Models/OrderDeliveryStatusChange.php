<?php

namespace App\Domain\Order\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to an order's delivery status. Append-only.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderDeliveryStatus|null $previous_status
 * @property OrderDeliveryStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property OrderStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read Order $order
 * @property-read User|null $changedBy
 */
class OrderDeliveryStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'order_delivery_status_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => OrderDeliveryStatus::class,
            'new_status' => OrderDeliveryStatus::class,
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
