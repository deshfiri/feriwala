<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stockable unit held in one warehouse (§19).
 *
 * The unit is the product itself when it has no variations, or one variation
 * when it does. The six figures are the buckets a unit can be in; nothing but
 * the stock service writes them (P3-23), and none can go below zero.
 *
 * @property int $id
 * @property string $public_id
 * @property int $warehouse_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $available
 * @property int $reserved
 * @property int $processing
 * @property int $sold
 * @property int $returned
 * @property int $damaged
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Warehouse $warehouse
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class StockItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available' => 'integer',
            'reserved' => 'integer',
            'processing' => 'integer',
            'sold' => 'integer',
            'returned' => 'integer',
            'damaged' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * The SKU this item holds: the variation's, or the product's own.
     */
    public function sku(): string
    {
        return $this->variant !== null ? $this->variant->sku : $this->product->sku;
    }

    /**
     * Every bucket's figure, keyed by bucket.
     *
     * @return array<string, int>
     */
    public function buckets(): array
    {
        $figures = [];

        foreach (StockBucket::cases() as $bucket) {
            $figures[$bucket->value] = (int) $this->getAttribute($bucket->value);
        }

        return $figures;
    }
}
