<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\PartnerStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a Partner's own stock, with every bucket's figure before and
 * after (D34). Append-only in the database; written only by
 * {@see PartnerStock}, in the same transaction as the
 * figures it explains.
 *
 * @property int $id
 * @property int $partner_stock_item_id
 * @property int $business_account_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property PartnerStockMovementType $type
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
 * @property-read PartnerStockItem $item
 */
class PartnerStockMovement extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PartnerStockMovementType::class,
            'from_bucket' => StockBucket::class,
            'to_bucket' => StockBucket::class,
            'quantity' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PartnerStockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PartnerStockItem::class, 'partner_stock_item_id');
    }
}
