<?php

namespace App\Domain\Sourcing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Says a member product's variation fulfils one canonical variation.
 *
 * Group membership alone is never enough: Black / M does not fulfil Blue / L
 * because both products are in the same group. A product with no variations
 * maps at product level (null variation on both sides).
 *
 * @property int $id
 * @property string $public_id
 * @property int $sourcing_group_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $canonical_product_variant_id
 * @property SourcingMappingStatus $status
 * @property int $added_by
 * @property string $added_reason
 * @property int|null $removed_by
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removal_reason
 * @property-read ProductSourcingGroup $group
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read ProductVariant|null $canonicalVariant
 */
class ProductSourcingVariantMapping extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SourcingMappingStatus::class,
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductSourcingGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductSourcingGroup::class, 'sourcing_group_id');
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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function canonicalVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'canonical_product_variant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * @param  Builder<ProductSourcingVariantMapping>  $query
     * @return Builder<ProductSourcingVariantMapping>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SourcingMappingStatus::Active);
    }
}
