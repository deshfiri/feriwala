<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int|null $product_id
 * @property array<string, mixed>|null $product_id_snapshot
 * @property array<string, mixed>|null $product_variant_id_snapshot
 * @property int|null $product_variant_id
 * @property string $sku
 * @property string $product_name
 * @property string|null $variant_label
 * @property int $quantity
 * @property string $currency_code
 * @property Money $unit_price
 * @property Money $line_subtotal
 * @property Money $discount
 * @property Money $tax
 * @property Money $tax_included
 * @property Money $line_total
 * @property Money|null $resale_amount a Non-Conditional account's declared resale/COD amount for this line
 * @property Money|null $product_cost what Feriwala is owed for a line filled from central stock for an account that never bought it
 * @property Money|null $platform_retained_amount the suggested selling price times the quantity, frozen at placement: what the platform keeps from that line, and the base the partner's profit is measured from
 * @property string|null $tax_code
 * @property int|null $tax_rate_basis_points
 * @property string|null $tax_mode
 * @property int|null $stock_reservation_id
 * @property int|null $supplier_id
 * @property int|null $supplier_offer_id
 * @property int|null $supplier_offer_price_change_id
 * @property Money|null $supplier_rate
 * @property Money|null $platform_rate
 * @property Money|null $platform_margin
 * @property string|null $supplier_currency_code
 * @property int|null $supplier_allocated_quantity
 * @property CarbonImmutable|null $supplier_allocated_at
 * @property int|null $sourcing_group_id legacy: set only on lines written while sourcing groups drove allocation; never written now
 * @property int|null $sourcing_canonical_product_id legacy, see above
 * @property int|null $sourcing_canonical_variant_id legacy, see above
 * @property CarbonImmutable|null $created_at
 * @property-read Order $order
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 * @property-read StockReservation|null $stockReservation
 * @property-read Supplier|null $supplier
 * @property-read SupplierOffer|null $supplierOffer
 * @property-read SupplierPayable|null $supplierPayable
 * @property-read OrderItemAllocation|null $activeAllocation
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
            'product_id_snapshot' => 'array',
            'product_variant_id_snapshot' => 'array',
            'unit_price' => MoneyCast::class,
            'line_subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'tax_included' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'resale_amount' => MoneyCast::class,
            'product_cost' => MoneyCast::class,
            'platform_retained_amount' => MoneyCast::class,
            'tax_rate_basis_points' => 'integer',
            'supplier_rate' => MoneyCast::class.':supplier_currency_code',
            'platform_rate' => MoneyCast::class.':supplier_currency_code',
            'platform_margin' => MoneyCast::class.':supplier_currency_code',
            'supplier_allocated_quantity' => 'integer',
            'supplier_allocated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether this line's product cost was left to be recovered from the
     * proceeds of the sale: a Non-Conditional wholesale line, or a storefront
     * line filled from central stock for an account that never bought it.
     */
    public function defersProductCost(): bool
    {
        return $this->platform_retained_amount !== null
            || $this->product_cost !== null
            || $this->order->isNonConditional();
    }

    /**
     * What is recovered from this line's proceeds before the reseller earns.
     *
     * A storefront line filled from central stock for an account that never
     * bought it keeps the platform's suggested selling price, snapshotted when
     * the order was placed: the partner's profit is what the store sold it for
     * above that, and never depends on the product's base cost. The tax
     * collected on the line is never the partner's either, so it is kept too.
     * A line placed before that snapshot existed keeps the product cost it
     * snapshotted; a wholesale line keeps its whole total.
     */
    public function recoverableAmount(): Money
    {
        // Paid for in full at checkout: nothing is recovered from the proceeds.
        if ($this->order->productCostWasPaid()) {
            return Money::zero($this->line_total->currency);
        }

        $kept = $this->platform_retained_amount ?? $this->product_cost;

        return $kept === null
            ? $this->line_total
            : $kept->plus($this->tax)->plus($this->tax_included);
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

    /**
     * This line's reseller-earning settlement, for a Non-Conditional order
     * (D-new). Null until `Delivered` is reached at least once.
     *
     * @return HasOne<OrderProceedsSettlement, $this>
     */
    public function proceedsSettlement(): HasOne
    {
        return $this->hasOne(OrderProceedsSettlement::class);
    }

    /**
     * The staff-chosen source currently holding this line, if any
     * ({@see AllocateOrderLineSource}) — separate
     * from the columns above, which snapshot what a dropshipping/wholesale
     * line was allocated to at placement time and are never rewritten.
     *
     * Only ever the line's single source before split allocation
     * (Advanced Order Management batch, Commit 3); {@see activeAllocations()}
     * is the one to use now that a line may hold more than one at once —
     * kept rather than removed since a non-split line's "the one source" is
     * still a meaningful question, and several existing call sites ask it.
     *
     * @return HasOne<OrderItemAllocation, $this>
     */
    public function activeAllocation(): HasOne
    {
        return $this->hasOne(OrderItemAllocation::class)->where('status', AllocationStatus::Active);
    }

    /**
     * Every source currently holding part of this line — one row when the
     * line is not split, several when it is.
     *
     * @return HasMany<OrderItemAllocation, $this>
     */
    public function activeAllocations(): HasMany
    {
        return $this->hasMany(OrderItemAllocation::class)->where('status', AllocationStatus::Active);
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
        return $this->belongsTo(Product::class)->withTrashed();
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
