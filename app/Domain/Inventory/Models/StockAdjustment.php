<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock changed by hand, with who and why (§19, P3-24).
 *
 * Append-only in the database, and linked one-to-one to the movement it made.
 *
 * @property int $id
 * @property string $public_id
 * @property int $stock_item_id
 * @property int $stock_movement_id
 * @property StockAdjustmentKind $kind
 * @property int $quantity
 * @property string $reason
 * @property int $actor_id
 * @property CarbonImmutable $created_at
 * @property-read StockItem $item
 * @property-read StockMovement $movement
 * @property-read User $actor
 */
class StockAdjustment extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StockAdjustmentKind::class,
            'quantity' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
