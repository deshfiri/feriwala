<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\StockLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of central stock, with every bucket's figure before and after
 * (§19, P3-23).
 *
 * Append-only in the database. Written only by {@see StockLedger},
 * in the same transaction as the figures it explains.
 *
 * @property int $id
 * @property string $public_id
 * @property int $stock_item_id
 * @property int $warehouse_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property array<string, mixed>|null $product_id_snapshot
 * @property array<string, mixed>|null $product_variant_id_snapshot
 * @property StockMovementType $type
 * @property StockBucket|null $from_bucket
 * @property StockBucket|null $to_bucket
 * @property int $quantity
 * @property array<string, int> $before
 * @property array<string, int> $after
 * @property string|null $reason
 * @property int|null $actor_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $idempotency_key
 * @property CarbonImmutable $occurred_at
 * @property-read StockItem $item
 * @property-read User|null $actor
 */
class StockMovement extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'from_bucket' => StockBucket::class,
            'to_bucket' => StockBucket::class,
            'quantity' => 'integer',
            'product_id_snapshot' => 'array',
            'product_variant_id_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * Every bucket's figure before the movement, in bucket order.
     *
     * @return Attribute<array<string, int>, array<string, int>>
     */
    protected function before(): Attribute
    {
        return $this->snapshot();
    }

    /**
     * Every bucket's figure after the movement, in bucket order.
     *
     * @return Attribute<array<string, int>, array<string, int>>
     */
    protected function after(): Attribute
    {
        return $this->snapshot();
    }

    /**
     * A figures snapshot, read back in {@see StockBucket} order.
     *
     * Stored as `jsonb`, which keeps its own key order rather than the one it
     * was written in — so a history read back straight would list the same
     * figures shuffled, and two snapshots holding identical figures would not
     * compare as identical.
     *
     * @return Attribute<array<string, int>, array<string, int>>
     */
    protected function snapshot(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                /** @var array<string, int> $figures */
                $figures = json_decode((string) $value, true) ?: [];
                $ordered = [];

                foreach (StockBucket::values() as $bucket) {
                    $ordered[$bucket] = (int) ($figures[$bucket] ?? 0);
                }

                return $ordered;
            },
            set: fn (array $figures): string => (string) json_encode($figures),
        );
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
