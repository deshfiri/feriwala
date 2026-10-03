<?php

namespace App\Domain\Sourcing\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ProductSourcingGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff-declared set of catalogue products that can fulfil the same ordered
 * product (see the migration's own docblock).
 *
 * Not a category: this decides what may *fulfil* an order line, never how the
 * catalogue is browsed, and it is never inferred from names or SKUs.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name_en
 * @property string $name_bn
 * @property string|null $description
 * @property bool $is_active
 * @property int $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ProductSourcingGroup extends Model
{
    /** @use HasFactory<ProductSourcingGroupFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ProductSourcingGroupFactory
    {
        return ProductSourcingGroupFactory::new();
    }

    /**
     * @return HasMany<ProductSourcingGroupProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(ProductSourcingGroupProduct::class, 'sourcing_group_id');
    }

    /**
     * @return HasMany<ProductSourcingVariantMapping, $this>
     */
    public function variantMappings(): HasMany
    {
        return $this->hasMany(ProductSourcingVariantMapping::class, 'sourcing_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The name shown to staff, in the language they are working in.
     */
    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'bn' && filled($this->name_bn)
            ? $this->name_bn
            : $this->name_en;
    }

    /**
     * @param  Builder<ProductSourcingGroup>  $query
     * @return Builder<ProductSourcingGroup>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
