<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferPriceChange;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Which source one order line is being fulfilled from, and every source it was
 * assigned to before.
 *
 * A decision, frozen. The source, its cost, the selling price, the margin, and
 * who chose it are locked by the database the moment the row exists — a payable
 * is calculated from these figures and an audit trail is read against them, so
 * a rate edited afterwards would silently restate both. Only the workflow
 * columns move: the status, who released it and why, and the allocation that
 * superseded it.
 *
 * Exactly one allocation per line is {@see AllocationStatus::Active} at a time,
 * by partial unique index. That is the no-split rule and the
 * never-two-reservations rule enforced by PostgreSQL rather than by the care of
 * whichever action happens to be running.
 *
 * Confidential in the way the Supplier Rate is (D25): `unit_cost` and
 * `expected_margin` are staff figures and never reach a Client, a Partner, a
 * Storefront API payload or a partner-facing order view.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $order_item_id
 * @property AllocationSourceType $source_type
 * @property int|null $warehouse_id
 * @property int|null $linked_stock_item_id
 * @property int|null $supplier_id
 * @property int|null $supplier_offer_id
 * @property int|null $supplier_offer_price_change_id
 * @property int|null $stock_reservation_id
 * @property int $quantity
 * @property Money $unit_cost
 * @property Money $platform_rate
 * @property Money $expected_margin
 * @property string $currency_code
 * @property AllocationStatus $status
 * @property int|null $allocated_by
 * @property CarbonImmutable $allocated_at
 * @property string|null $allocation_reason
 * @property int|null $released_by
 * @property CarbonImmutable|null $released_at
 * @property string|null $release_reason
 * @property int|null $superseded_by_allocation_id
 * @property string $idempotency_key
 * @property-read Order $order
 * @property-read OrderItem $orderItem
 * @property-read Warehouse|null $warehouse
 * @property-read StockItem|null $linkedStockItem
 * @property-read Supplier|null $supplier
 * @property-read SupplierOffer|null $offer
 * @property-read SupplierOfferPriceChange|null $priceVersion
 * @property-read StockReservation|null $reservation
 * @property-read User|null $allocatedBy
 * @property-read SupplierPayable|null $payable
 * @property-read SupplierFulfilmentCommitment|null $fulfilmentCommitment
 */
class OrderItemAllocation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => AllocationSourceType::class,
            'status' => AllocationStatus::class,
            'quantity' => 'integer',
            'unit_cost' => MoneyCast::class,
            'platform_rate' => MoneyCast::class,
            'expected_margin' => MoneyCast::class,
            'allocated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Which stock item this allocation actually draws on, when it reaches a
     * different product/variation than the order line's own through a
     * confirmed {@see ProductSourceLink}. Null for
     * the historical, exact-match case, where `warehouse_id` plus the order
     * line's own product already say everything.
     *
     * @return BelongsTo<StockItem, $this>
     */
    public function linkedStockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'linked_stock_item_id');
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
    public function offer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }

    /**
     * @return BelongsTo<SupplierOfferPriceChange, $this>
     */
    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(SupplierOfferPriceChange::class, 'supplier_offer_price_change_id');
    }

    /**
     * @return BelongsTo<StockReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /**
     * What this allocation made Feriwala owe, if anything.
     *
     * @return HasOne<SupplierPayable, $this>
     */
    public function payable(): HasOne
    {
        return $this->hasOne(SupplierPayable::class, 'order_item_allocation_id');
    }

    /**
     * The capacity reservation backing this allocation, when it is one of
     * the offer's non-ready-stock kind (Supplier Bulk Product Listing
     * batch, correction 7). Null for a ready_stock or warehouse allocation.
     *
     * @return HasOne<SupplierFulfilmentCommitment, $this>
     */
    public function fulfilmentCommitment(): HasOne
    {
        return $this->hasOne(SupplierFulfilmentCommitment::class, 'order_item_allocation_id');
    }

    /**
     * The live allocations — the ones actually holding stock.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AllocationStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Whether this allocation is what makes Feriwala owe a Supplier.
     *
     * Asked of the source type rather than of the presence of a payable, so the
     * answer is the same before the payable is written as after it.
     */
    public function createsSupplierPayable(): bool
    {
        return $this->source_type->createsSupplierPayable();
    }
}
