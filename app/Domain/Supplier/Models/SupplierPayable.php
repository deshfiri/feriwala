<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What Feriwala owes one Supplier for one allocated order line (D25, P13-22).
 *
 * A claim, never money: it credits no wallet and creates nothing withdrawable.
 * The figures — Supplier, line, offer, price version, quantity, Supplier Rate,
 * gross — are locked by the database from the moment the row exists; only the
 * workflow columns move, and only through {@see HasStateMachine::transitionTo()}
 * inside the payable actions. A return appends a {@see SupplierPayableReversal}
 * rather than editing anything here.
 *
 * Confidential in the way the Supplier Rate is: never part of a Client/Partner
 * response, a Partner Website payload or a public notification.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $supplier_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $supplier_offer_id
 * @property int $supplier_offer_price_change_id
 * @property int $quantity
 * @property Money $supplier_rate
 * @property Money $gross_amount
 * @property string $currency_code
 * @property PayableStatus $status
 * @property string $triggering_event
 * @property string $idempotency_key
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $payment_settled_at
 * @property CarbonImmutable|null $eligible_at
 * @property CarbonImmutable|null $held_at
 * @property string|null $hold_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $settled_at
 * @property string|null $settlement_reference
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 * @property-read Order $order
 * @property-read OrderItem $orderItem
 * @property-read SupplierOffer $offer
 * @property-read SupplierOfferPriceChange $priceVersion
 * @property-read Collection<int, SupplierPayableReversal> $reversals
 * @property-read Collection<int, SupplierPayableStatusChange> $statusHistory
 */
class SupplierPayable extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayableStatus::class,
            'quantity' => 'integer',
            'supplier_rate' => MoneyCast::class,
            'gross_amount' => MoneyCast::class,
            'delivered_at' => 'immutable_datetime',
            'payment_settled_at' => 'immutable_datetime',
            'eligible_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::SupplierPayable;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * @return HasMany<SupplierPayableReversal, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(SupplierPayableReversal::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupplierPayableStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupplierPayableStatusChange::class)->orderBy('id');
    }

    public function reversedQuantity(): int
    {
        return (int) $this->reversals()->sum('quantity');
    }

    /**
     * What has been taken back so far, in the payable's own currency.
     */
    public function reversedAmount(): Money
    {
        return Money::fromDecimal(
            (string) ($this->reversals()->sum('amount') ?: '0'),
            $this->gross_amount->currency,
        );
    }

    /**
     * What still stands: the gross amount less every reversal.
     */
    public function netAmount(): Money
    {
        return $this->gross_amount->minus($this->reversedAmount());
    }
}
