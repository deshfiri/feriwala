<?php

namespace App\Domain\Courier\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw tracking event a shipment has recorded (Advanced Order Management
 * batch, Commit 5). Append-only -- see the migration for the database guard.
 *
 * @property int $id
 * @property int $shipment_id
 * @property string $event_code
 * @property string|null $description
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed>|null $raw_payload
 * @property string $source
 * @property string|null $external_event_id
 */
class ShipmentTrackingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
