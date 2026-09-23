<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property int|null $supplier_id
 * @property int|null $supplier_offer_id
 * @property int|null $supplier_offer_price_change_id
 * @property Money|null $supplier_rate_minor
 * @property Money|null $platform_rate_minor
 * @property Money|null $platform_margin_minor
 * @property string|null $supplier_currency_code
 * @property int|null $supplier_allocated_quantity
 * @property CarbonImmutable|null $supplier_allocated_at
 * @property CarbonImmutable|null $created_at
 * @property-read Order $order
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read StockReservation|null $stockReservation
 * @property-read Supplier|null $supplier
 * @property-read SupplierOffer|null $supplierOffer
 * @property-read SupplierPayable|null $supplierPayable
 *
 * A Supplier-backed line also snapshots, once and immutably, the Supplier, the
 * exact offer and price version it was allocated at, both rates and the margin
 * (D25, P13-21). Those columns are Feriwala's own: never part of a Client,
 * Partner or Storefront payload — {@see isSupplierBacked()}.
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
            'supplier_rate_minor' => MoneyCast::class.':supplier_currency_code',
            'platform_rate_minor' => MoneyCast::class.':supplier_currency_code',
            'platform_margin_minor' => MoneyCast::class.':supplier_currency_code',
            'supplier_allocated_quantity' => 'integer',
            'supplier_allocated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether this line was allocated to one Supplier's offer (D25, P13-21).
     */
    public function isSupplierBacked(): bool
    {
        return $this->supplier_offer_id !== null;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function supplierOffer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class);
    }

    /**
     * @return HasOne<SupplierPayable, $this>
     */
    public function supplierPayable(): HasOne
    {
        return $this->hasOne(SupplierPayable::class);
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
