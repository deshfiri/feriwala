<?php

namespace App\Domain\Sourcing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One catalogue product's membership of a sourcing group.
 *
 * Never deleted -- removing a member records who, when and why, and keeps the
 * row as history.
 *
 * @property int $id
 * @property string $public_id
 * @property int $sourcing_group_id
 * @property int $product_id
 * @property bool $is_canonical
 * @property SourcingMappingStatus $status
 * @property int $added_by
 * @property string $added_reason
 * @property int|null $removed_by
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removal_reason
 * @property-read ProductSourcingGroup $group
 * @property-read Product $product
 */
class ProductSourcingGroupProduct extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_canonical' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function isActive(): bool
    {
        return $this->status === SourcingMappingStatus::Active;
    }

    /**
     * @param  Builder<ProductSourcingGroupProduct>  $query
     * @return Builder<ProductSourcingGroupProduct>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SourcingMappingStatus::Active);
    }
}
