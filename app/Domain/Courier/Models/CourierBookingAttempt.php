<?php

namespace App\Domain\Courier\Models;

use App\Concerns\HasPublicId;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to book an order's parcel with a courier's API (D8).
 *
 * Written *before* the call, so a lost reply leaves something to reconcile.
 * Holds a summary of the request (the invoice reference and COD amount) and a
 * safe failure code -- never credentials, never the customer's name, number or
 * address, never a raw response.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $courier_provider_id
 * @property int|null $shipment_id
 * @property int|null $attempted_by
 * @property CourierBookingStatus $status
 * @property string $idempotency_key
 * @property array<string, string>|null $request_snapshot
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property-read Order $order
 * @property-read Shipment|null $shipment
 */
class CourierBookingAttempt extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourierBookingStatus::class,
            'request_snapshot' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
