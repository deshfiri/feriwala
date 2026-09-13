<?php

namespace App\Domain\Catalog\Models;

use App\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value of a shared attribute — "M" of Size, "Navy" of Colour (§11.1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_attribute_id
 * @property string $value
 * @property int $sort_order
 * @property-read ProductAttribute $attribute
 */
class ProductAttributeValue extends Model
{
    use HasPublicId;

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
     * @return BelongsTo<ProductAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    /**
     * Whether any variant carries this value — the reason it cannot be removed.
     */
    public function isInUse(): bool
    {
        return $this->getConnection()
            ->table('product_variant_values')
            ->where('product_attribute_value_id', $this->id)
            ->exists();
    }
}
