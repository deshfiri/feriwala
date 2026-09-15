<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockReservation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of an order: what was bought, as it was named and priced then (P6-1).
 *
 * A snapshot. The product may be renamed, repriced or withdrawn tomorrow; this
 * line goes on saying what was sold, at what price, with what discount and tax.
 * Written once, with the order, and never changed or removed — here and in the
 * database.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $line_number
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $sku
 * @property string $product_name
 * @property string|null $variant_label
 * @property int $quantity
 * @property string $currency_code
 * @property Money $unit_price_minor
 * @property Money $line_subtotal_minor
 * @property Money $discount_minor
 * @property Money $tax_minor
 * @property Money $tax_included_minor
 * @property Money $line_total_minor
 * @property string|null $tax_code
 * @property int|null $tax_rate_basis_points
 * @property string|null $tax_mode
 * @property int|null $stock_reservation_id
 * @property CarbonImmutable|null $created_at
 * @property-read Order $order
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read StockReservation|null $stockReservation
 */
class OrderItem extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'line_number' => 'integer',
            'unit_price_minor' => MoneyCast::class,
            'line_subtotal_minor' => MoneyCast::class,
            'discount_minor' => MoneyCast::class,
            'tax_minor' => MoneyCast::class,
            'tax_included_minor' => MoneyCast::class,
            'line_total_minor' => MoneyCast::class,
            'tax_rate_basis_points' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An order line is a snapshot of what was bought and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('An order line is a snapshot of what was bought and cannot be removed.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * @return BelongsTo<StockReservation, $this>
     */
    public function stockReservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class);
    }
}
