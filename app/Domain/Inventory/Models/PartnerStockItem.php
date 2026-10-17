<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\PartnerStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The units of one Product Variation a Partner owns, in the buckets a unit can
 * be in (D34).
 *
 * Only {@see PartnerStock} writes the figures, and none can
 * go below zero. `available` is what the Partner's storefront may sell.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
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
 * @property-read BusinessAccount $account
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class PartnerStockItem extends Model
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
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    /**
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
     * @return HasMany<PartnerStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PartnerStockMovement::class);
    }

    /**
     * Every bucket's figure, keyed by bucket.
     *
     * @return array<string, int>
     */
    public function buckets(): array
    {
        $figures = [];

        foreach ([
            StockBucket::Available, StockBucket::Reserved, StockBucket::Processing,
            StockBucket::Sold, StockBucket::Returned, StockBucket::Damaged,
        ] as $bucket) {
            $figures[$bucket->value] = (int) $this->getAttribute($bucket->value);
        }

        return $figures;
    }

    /**
     * Every unit the Partner has bought and still accounts for, in any bucket.
     */
    public function purchased(): int
    {
        return array_sum($this->buckets());
    }

    /**
     * Units sold through a storefront: committed to a confirmed order, or
     * delivered.
     */
    public function soldThroughStorefront(): int
    {
        return $this->processing + $this->sold;
    }

    /**
     * Units back from a customer and not yet sellable, or damaged.
     */
    public function unavailable(): int
    {
        return $this->returned + $this->damaged;
    }
}
