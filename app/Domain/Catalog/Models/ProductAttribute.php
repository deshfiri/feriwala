<?php

namespace App\Domain\Catalog\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An attribute shared across the catalogue — Size, Colour, Material (§11.1).
 *
 * Shared rather than per product, so a storefront's filter has one "Colour"
 * with one "Navy" in it, whichever products carry it.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 * @property-read Collection<int, ProductAttributeValue> $values
 * @property-read int|null $variants_count
 */
class ProductAttribute extends Model
{
    use HasPublicId, HasSlug {
        HasPublicId::getRouteKeyName insteadof HasSlug;
    }

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
