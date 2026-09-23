<?php

namespace App\Domain\Catalog\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One variation of a product — a combination of attribute values with its own
 * SKU (§11.1).
 *
 * Its combination is fixed once created. Changing "M, Navy" into "L, Navy" in
 * place would make every later record of the old variant describe a garment it
 * never was; a different combination is a new variant, and the old one is
 * switched off.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string $combination_key
 * @property string $currency_code
 * @property Money|null $wholesale_price
 * @property Money|null $base_cost
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property-read Product $product
 * @property-read Collection<int, ProductAttributeValue> $values
 */
class ProductVariant extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * A variation added, changed or removed is the product changing:
     * storefronts find what to re-read by the product's `updated_at`
     * (contract §5.1, P5-22).
     *
     * @var list<string>
     */
    protected $touches = ['product'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wholesale_price' => MoneyCast::class,
            'base_cost' => MoneyCast::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsToMany<ProductAttributeValue, $this>
     */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_values',
            'product_variant_id',
            'product_attribute_value_id',
        )->withPivot('product_attribute_id');
    }

    /**
     * The wholesale price that applies: this variant's own, else the product's.
     */
    public function effectiveWholesalePrice(): Money
    {
        return $this->wholesale_price ?? $this->product->wholesale_price;
    }

    /**
     * The canonical key for a set of attribute-value ids.
     *
     * Sorted, so the order values were chosen in cannot make one combination
     * look like two.
     *
     * @param  array<int, int>  $valueIds
     */
    public static function combinationKeyFor(array $valueIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $valueIds)));
        sort($ids);

        return implode('-', $ids);
    }
}
