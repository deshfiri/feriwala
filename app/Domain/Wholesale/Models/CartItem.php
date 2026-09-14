<?php

namespace App\Domain\Wholesale\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stockable unit in a cart, and how many are wanted (§14, P4-3).
 *
 * The unit is the product itself when it has no variations, or one variation
 * when it does. `unit_price_seen_minor` is what the person was last shown for
 * this line — kept to point out a price that has changed since, never charged.
 *
 * @property int $id
 * @property string $public_id
 * @property int $cart_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity
 * @property Money|null $unit_price_seen_minor
 * @property string $currency_code
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Cart $cart
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class CartItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_seen_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
