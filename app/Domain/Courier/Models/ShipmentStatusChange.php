<?php

namespace App\Domain\Courier\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a shipment's status (Advanced Order Management
 * batch, Commit 5). Append-only.
 *
 * @property int $id
 * @property int $shipment_id
 * @property OrderCourierStatus|null $previous_status
 * @property OrderCourierStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property ShipmentStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class ShipmentStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'shipment_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => OrderCourierStatus::class,
            'new_status' => OrderCourierStatus::class,
            'source' => ShipmentStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
