<?php

namespace App\Domain\Courier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Courier\Actions\RecordManualCourierStatusUpdate;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One courier-neutral shipment (Advanced Order Management batch, Commit 5;
 * §18, §21, P6.C).
 *
 * `status` carries {@see OrderCourierStatus} directly -- see the migration's
 * docblock for why there is no separate `ShipmentStatus` enum. Advancing it
 * is {@see RecordManualCourierStatusUpdate}, never
 * a direct attribute assignment; that action also mirrors the move onto the
 * order's own {@see OrderCourierStatus} and, where there is an unambiguous
 * equivalent, its {@see OrderDeliveryStatus}.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $order_id
 * @property int $courier_provider_id
 * @property OrderCourierStatus $status
 * @property string|null $tracking_number
 * @property string $currency_code
 * @property Money $delivery_charge
 * @property array<string, mixed>|null $delivery_charge_rule_snapshot
 * @property Money|null $cod_amount
 * @property Money|null $return_charge
 * @property CarbonImmutable|null $pickup_requested_at
 * @property string|null $label_path
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Order $order
 * @property-read CourierProvider $courierProvider
 * @property-read User|null $cancelledBy
 * @property-read Collection<int, ShipmentPackage> $packages
 * @property-read Collection<int, ShipmentTrackingEvent> $trackingEvents
 * @property-read Collection<int, ShipmentStatusChange> $statusHistory
 */
class Shipment extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderCourierStatus::class,
            'delivery_charge' => MoneyCast::class,
            'cod_amount' => MoneyCast::class,
            'return_charge' => MoneyCast::class,
            'delivery_charge_rule_snapshot' => 'array',
            'pickup_requested_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Shipment;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<CourierProvider, $this>
     */
    public function courierProvider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<ShipmentPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class);
    }

    /**
     * @return HasMany<ShipmentTrackingEvent, $this>
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class)->orderBy('occurred_at');
    }

    /**
     * @return HasMany<ShipmentStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ShipmentStatusChange::class)->orderBy('id');
    }
}
