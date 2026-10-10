<?php

namespace App\Domain\Sourcing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Enums\ProductLinkStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff-confirmed "these two Product records are the same physical Product".
 *
 * An edge, not a group: neither side is a master, and the pair is stored once
 * with the lower Product id as side A. Never deleted — unlinking records who,
 * when and why.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_a_id
 * @property int $product_b_id
 * @property ProductLinkStatus $status
 * @property int $linked_by
 * @property CarbonImmutable $linked_at
 * @property string|null $link_reason
 * @property int|null $unlinked_by
 * @property CarbonImmutable|null $unlinked_at
 * @property string|null $unlink_reason
 * @property-read Product $productA
 * @property-read Product $productB
 * @property-read User $linker
 */
class ProductLink extends Model
{
    use HasPublicId;

    protected $table = 'product_same_links';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductLinkStatus::class,
            'linked_at' => 'immutable_datetime',
            'unlinked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function productA(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_a_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function productB(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_b_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /**
     * @return HasMany<ProductLinkVariantMapping, $this>
     */
    public function variantMappings(): HasMany
    {
        return $this->hasMany(ProductLinkVariantMapping::class, 'product_link_id');
    }

    /**
     * The Product on the other end of this link from `$productId`.
     */
    public function otherProductId(int $productId): int
    {
        return $this->product_a_id === $productId ? $this->product_b_id : $this->product_a_id;
    }

    /**
     * @param  Builder<ProductLink>  $query
     * @return Builder<ProductLink>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductLinkStatus::Active);
    }

    /**
     * @param  Builder<ProductLink>  $query
     * @return Builder<ProductLink>
     */
    public function scopeTouching(Builder $query, int $productId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('product_a_id', $productId)
            ->orWhere('product_b_id', $productId));
    }
}
