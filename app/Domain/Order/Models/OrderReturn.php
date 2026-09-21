<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Website\Models\Website;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Goods a customer is sending back, and what was decided (§18.2, §26.3,
 * contract §6.3, P6-12).
 *
 * A request, not an action: creating one moves no stock and no money. It
 * carries who asked, why, which lines and how many, and then accumulates the
 * decisions taken about it — each written to the shared status history with the
 * reason behind it, so the record reads as an account of what happened rather
 * than a final state with no story.
 *
 * Never deleted, here and in the database. A return that was asked for stays
 * asked for, even if it was refused.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $order_id
 * @property int $business_account_id
 * @property int|null $website_id
 * @property OrderStatusChangeSource $source
 * @property ReturnStatus $status
 * @property ReturnReason $reason
 * @property string|null $customer_note
 * @property array<array-key, mixed>|null $evidence
 * @property string|null $decision_note
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property int|null $received_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $cancelled_at
 * @property ReturnRefundState $refund_state
 * @property int|null $refund_request_id
 * @property Money|null $refund_amount_minor
 * @property string $currency_code
 * @property string|null $refund_note
 * @property string|null $idempotency_key
 * @property-read Order $order
 * @property-read BusinessAccount $businessAccount
 * @property-read Website|null $website
 * @property-read RefundRequest|null $refundRequest
 * @property-read Collection<int, OrderReturnItem> $items
 * @property-read Collection<int, OrderReturnStatusChange> $statusHistory
 */
class OrderReturn extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * Knowing it would let somebody collide with a return deliberately.
     *
     * @var list<string>
     */
    protected $hidden = ['idempotency_key'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException('A return is never deleted; a refused one is refused, not removed.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => OrderStatusChangeSource::class,
            'status' => ReturnStatus::class,
            'reason' => ReturnReason::class,
            'refund_state' => ReturnRefundState::class,
            'evidence' => 'array',
            'refund_amount_minor' => MoneyCast::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::GoodsReturn;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<RefundRequest, $this>
     */
    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderReturnStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderReturnStatusChange::class)->orderBy('id');
    }

    /**
     * Move the return along and record why, in one transaction.
     *
     * The caller has already decided the move is allowed for this return and
     * locked it; this refuses a move the map does not have and writes the move
     * and its history together.
     */
    public function moveTo(ReturnStatus $to, StatusChange $change, OrderStatusChangeSource $source): OrderReturnStatusChange
    {
        /** @var OrderReturnStatusChange $entry */
        $entry = $this->transitionWithHistory($to, $change, ['source' => $source]);

        return $entry;
    }

    /**
     * Record the status a return was created in.
     */
    public function recordRequest(StatusChange $change, OrderStatusChangeSource $source): OrderReturnStatusChange
    {
        /** @var OrderReturnStatusChange $entry */
        $entry = $this->recordStatusChange(null, $this->status, $change, ['source' => $source]);

        return $entry;
    }

    /**
     * Whether this return is against an order paid on delivery.
     *
     * The money never came through a gateway, so it cannot go back through one
     * (§28): such a refund is settled by a person, not by this system.
     */
    public function isCashOnDelivery(): bool
    {
        return $this->order->isCashOnDelivery();
    }
}
