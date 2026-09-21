<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One line of a return: what is coming back, how much of it, and where it
 * ended up (§19.1, P6-12).
 *
 * Three quantities, because they are three different facts and collapsing them
 * loses the argument: what the customer **asked** to send back, what Feriwala
 * **approved**, and what actually **arrived**. Each is capped by the one before
 * it, in the database.
 *
 * The movement that restored the goods is named here. That is what makes
 * restoring twice impossible rather than unlikely: the column is unique, so a
 * second restoration of the same line has nowhere to record itself.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property int $quantity
 * @property int|null $approved_quantity
 * @property int $received_quantity
 * @property ReturnDisposition|null $disposition
 * @property int|null $warehouse_id
 * @property int|null $stock_movement_id
 * @property CarbonImmutable|null $restored_at
 * @property Money|null $refund_amount_minor
 * @property string $currency_code
 * @property-read OrderReturn $orderReturn
 * @property-read OrderItem $orderItem
 * @property-read Warehouse|null $warehouse
 * @property-read StockMovement|null $stockMovement
 */
class OrderReturnItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException('A returned line is never deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'approved_quantity' => 'integer',
            'received_quantity' => 'integer',
            'disposition' => ReturnDisposition::class,
            'refund_amount_minor' => MoneyCast::class,
            'restored_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * Whether the goods on this line have already been put back into stock.
     */
    public function isRestored(): bool
    {
        return $this->stock_movement_id !== null;
    }
}
