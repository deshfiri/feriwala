<?php

namespace App\Domain\Sourcing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Says one variation of a linked Product is the same as one variation of the
 * other. Variation labels never create this on their own: Black / XL on one
 * Product is only Black / XL on the other because staff said so.
 *
 * A null side is the Product itself, for a Product with no variations.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_link_id
 * @property int|null $variant_a_id
 * @property int|null $variant_b_id
 * @property SourcingMappingStatus $status
 * @property int $mapped_by
 * @property CarbonImmutable $mapped_at
 * @property string|null $map_reason
 * @property int|null $removed_by
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removal_reason
 * @property-read ProductLink $link
 * @property-read ProductVariant|null $variantA
 * @property-read ProductVariant|null $variantB
 */
class ProductLinkVariantMapping extends Model
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
            'mapped_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(ProductLink::class, 'product_link_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variantA(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_a_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variantB(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_b_id');
    }

    /**
     * @param  Builder<ProductLinkVariantMapping>  $query
     * @return Builder<ProductLinkVariantMapping>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SourcingMappingStatus::Active);
    }
}
