<?php

namespace App\Domain\Order\Models;

use App\Concerns\HasPublicId;
use App\Domain\Inventory\Models\StockReservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock reservation made for an order after its first one lapsed (D36).
 *
 * A reservation never comes back from released or expired, and an order line
 * cannot be repointed, so a replacement is a new reservation with its own
 * reference, linked here to the line it holds stock for. Append-only in the
 * database; the original reservation is never touched.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_receivable_collection_id
 * @property int $order_item_id
 * @property int $stock_reservation_id
 * @property string $purpose
 * @property int $sequence
 * @property CarbonImmutable $created_at
 * @property-read OrderReceivableCollection $collection
 * @property-read OrderItem $item
 * @property-read StockReservation $reservation
 */
class OrderHoldReservation extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    /** Stock set aside again because the first reservation lapsed before collection. */
    public const RECOVERY = 'recovery';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderReceivableCollection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(OrderReceivableCollection::class, 'order_receivable_collection_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<StockReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }
}
