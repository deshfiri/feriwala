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
 * when it does. The seven figures are the buckets a unit can be in — the six of
 * P3-22 and, since P3-30, stock allocated to a business account; nothing but the
 * stock service writes them (P3-23), and none can go below zero.
 *
 * @property int $id
 * @property string $public_id
 * @property int $warehouse_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property array<string, mixed>|null $product_id_snapshot
 * @property array<string, mixed>|null $product_variant_id_snapshot
 * @property int $available
 * @property int $reserved
 * @property int $processing
 * @property int $sold
 * @property int $returned
 * @property int $damaged
 * @property int $allocated
 * @property int|null $low_stock_threshold
 * @property CarbonImmutable|null $low_stock_alerted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Warehouse $warehouse
 * @property-read Product|null $product
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
            'allocated' => 'integer',
            'low_stock_threshold' => 'integer',
            'product_id_snapshot' => 'array',
            'product_variant_id_snapshot' => 'array',
            'low_stock_alerted_at' => 'immutable_datetime',
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
     * The Product, trashed or not. Null once a Super Admin has force deleted it;
     * {@see productName()} and friends then read the snapshot kept on this row.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
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
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Who this stock is set aside for (P3-30).
     *
     * @return HasMany<StockAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(StockAllocation::class);
    }

    /**
     * Whether somebody asked to be told about this stock, and it has fallen to
     * that point (P3-29).
     */
    public function isLow(): bool
    {
        return $this->low_stock_threshold !== null && $this->available <= $this->low_stock_threshold;
    }

    /**
     * The SKU this item holds: the variation's, or the product's own.
     */
    public function sku(): string
    {
        $variant = $this->liveVariant();

        if ($variant !== null) {
            return $variant->sku;
        }

        if ($this->product_variant_id === null && $this->product_variant_id_snapshot !== null) {
            return (string) ($this->product_variant_id_snapshot['sku'] ?? '');
        }

        $product = $this->liveProduct();

        return $product !== null ? $product->sku : (string) ($this->product_id_snapshot['sku'] ?? '');
    }

    public function productName(): string
    {
        $product = $this->liveProduct();

        return $product !== null ? $product->name : (string) ($this->product_id_snapshot['name'] ?? '');
    }

    public function productPublicId(): ?string
    {
        $product = $this->liveProduct();

        return $product !== null ? $product->public_id : ($this->product_id_snapshot['public_id'] ?? null);
    }

    public function variantPublicId(): ?string
    {
        $variant = $this->liveVariant();

        return $variant !== null ? $variant->public_id : ($this->product_variant_id_snapshot['public_id'] ?? null);
    }

    /**
     * The Product while it exists, trashed or not; null once force deleted.
     */
    protected function liveProduct(): ?Product
    {
        return $this->product_id === null ? null : $this->product;
    }

    protected function liveVariant(): ?ProductVariant
    {
        return $this->product_variant_id === null ? null : $this->variant;
    }

    /**
     * Whether the Product this stock was held for has been force deleted.
     */
    public function productWasDeleted(): bool
    {
        return $this->product_id === null;
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
