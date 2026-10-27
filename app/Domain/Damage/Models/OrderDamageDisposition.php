<?php

namespace App\Domain\Damage\Models;

use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Order\Enums\ReturnDisposition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Where some of a damage case's units ended up (D35): one row per disposition,
 * with the one stock movement that put them there when any moved.
 *
 * A disposition is a decision about units. It is written once, with the
 * movement it caused, and never edited or deleted.
 *
 * @property int $id
 * @property int $order_damage_id
 * @property ReturnDisposition $disposition
 * @property int $quantity
 * @property StockBucket|null $from_bucket
 * @property StockBucket|null $to_bucket
 * @property int|null $stock_movement_id
 * @property int|null $partner_stock_movement_id
 * @property CarbonImmutable|null $moved_at
 * @property-read OrderDamage $damage
 */
class OrderDamageDisposition extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException('A disposition is never deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disposition' => ReturnDisposition::class,
            'from_bucket' => StockBucket::class,
            'to_bucket' => StockBucket::class,
            'moved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderDamage, $this>
     */
    public function damage(): BelongsTo
    {
        return $this->belongsTo(OrderDamage::class, 'order_damage_id');
    }
}
