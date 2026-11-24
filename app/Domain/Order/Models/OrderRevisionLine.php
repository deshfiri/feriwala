<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Domain\Order\Enums\RevisionLineChange;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What one revision did to one order line (D38): the line it superseded, the
 * line that took its place, and both readings side by side. Append-only.
 *
 * @property int $id
 * @property int $order_revision_id
 * @property RevisionLineChange $change
 * @property int|null $previous_order_item_id
 * @property int|null $new_order_item_id
 * @property string $sku
 * @property string $product_name
 * @property string|null $variant_label
 * @property int|null $quantity_before
 * @property int|null $quantity_after
 * @property string $currency_code
 * @property Money|null $unit_price_before
 * @property Money|null $unit_price_after
 * @property Money|null $line_total_before
 * @property Money|null $line_total_after
 * @property CarbonImmutable $created_at
 * @property-read OrderRevision $revision
 * @property-read OrderItem|null $previousItem
 * @property-read OrderItem|null $newItem
 */
class OrderRevisionLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'change' => RevisionLineChange::class,
            'unit_price_before' => MoneyCast::class,
            'unit_price_after' => MoneyCast::class,
            'line_total_before' => MoneyCast::class,
            'line_total_after' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A revision line is a record of a correction and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A revision line is a record of a correction and cannot be removed.'));
    }

    /**
     * @return BelongsTo<OrderRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(OrderRevision::class, 'order_revision_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function previousItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'previous_order_item_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function newItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'new_order_item_id');
    }
}
