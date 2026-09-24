<?php

namespace App\Domain\Catalog\Models;

use App\Casts\MoneyCast;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quantity band of wholesale pricing: from `min_quantity` units, this much
 * each (§11.1).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $min_quantity
 * @property string $currency_code
 * @property Money $unit_price
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class ProductPriceTier extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'unit_price' => MoneyCast::class,
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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
