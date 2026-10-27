<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Website\Models\Website;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use App\Support\StateMachine\TransitionableState;
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
 * @property int $workflow_version 1 for a return placed before D35, 2 for the case workflow
 * @property ReturnCaseType $case_type
 * @property ReturnInitiator|null $initiated_by_type
 * @property ReturnParty|null $on_behalf_of
 * @property ReturnParty|null $responsible_party
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property int|null $received_by
 * @property int|null $inspected_by
 * @property string|null $inspection_outcome
 * @property string|null $inspection_note
 * @property Money|null $original_delivery_charge
 * @property Money|null $return_charge
 * @property Money|null $exchange_delivery_charge
 * @property Money|null $other_adjustments
 * @property string|null $adjustment_note
 * @property int|null $original_order_id the order an exchange is of (the root, never an exchange order)
 * @property int|null $exchange_sequence 1 for the first exchange of that order, then 2, 3, …
 * @property string|null $exchange_number EX-{original number}, then -2, -3, …
 * @property int|null $exchange_order_id
 * @property int|null $previous_exchange_id
 * @property Money|null $exchange_additional_due what the customer owes for a dearer replacement
 * @property Money|null $exchange_refund_due what the customer is owed for a cheaper one
 * @property string|null $exchange_difference_state
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $inspected_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property ReturnRefundState $refund_state
 * @property int|null $refund_request_id
 * @property Money|null $refund_amount
 * @property string $currency_code
 * @property string|null $refund_note
 * @property string|null $idempotency_key
 * @property-read Order $order
 * @property-read BusinessAccount $businessAccount
 * @property-read Website|null $website
 * @property-read RefundRequest|null $refundRequest
 * @property-read Collection<int, OrderReturnItem> $items
 * @property-read Collection<int, OrderReturnStatusChange> $statusHistory
 * @property-read Collection<int, ReturnCharge> $charges
 * @property-read Collection<int, ReturnResponsibilityChange> $responsibilityChanges
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
            'workflow_version' => 'integer',
            'case_type' => ReturnCaseType::class,
            'initiated_by_type' => ReturnInitiator::class,
            'on_behalf_of' => ReturnParty::class,
            'responsible_party' => ReturnParty::class,
            'evidence' => 'array',
            'refund_amount' => MoneyCast::class,
            'original_delivery_charge' => MoneyCast::class,
            'return_charge' => MoneyCast::class,
            'exchange_delivery_charge' => MoneyCast::class,
            'other_adjustments' => MoneyCast::class,
            'exchange_additional_due' => MoneyCast::class,
            'exchange_refund_due' => MoneyCast::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'inspected_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
     * The replacement order this exchange sent.
     *
     * @return BelongsTo<Order, $this>
     */
    public function exchangeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'exchange_order_id');
    }

    /**
     * The order an exchange is of: always the original, even for a second
     * exchange of the replacement.
     *
     * @return BelongsTo<Order, $this>
     */
    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'original_order_id');
    }

    /**
     * The exchange this one follows, when the goods being exchanged were
     * themselves the replacement of an earlier exchange.
     *
     * @return BelongsTo<OrderReturn, $this>
     */
    public function previousExchange(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_exchange_id');
    }

    /**
     * @return HasMany<ReturnCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(ReturnCharge::class)->orderBy('id');
    }

    /**
     * The files of evidence on this case, oldest first (D35).
     *
     * @return HasMany<CaseEvidence, $this>
     */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(CaseEvidence::class, 'order_return_id')->orderBy('id');
    }

    /**
     * @return HasMany<ReturnResponsibilityChange, $this>
     */
    public function responsibilityChanges(): HasMany
    {
        return $this->hasMany(ReturnResponsibilityChange::class)->orderBy('id');
    }

    /**
     * Whether this return follows the case workflow (D35) rather than the
     * three-step flow it was placed under before.
     */
    public function usesCaseWorkflow(): bool
    {
        return (int) ($this->getAttribute('workflow_version') ?? 1) >= 2;
    }

    public function isExchange(): bool
    {
        return $this->case_type === ReturnCaseType::Exchange;
    }

    /**
     * The version-aware map: a return placed before D35 keeps the moves it had,
     * and a case follows the full progression. The status enum alone cannot
     * tell them apart.
     */
    public function canTransitionTo(TransitionableState $to, ?string $attribute = null): bool
    {
        $current = $this->currentState($attribute);

        if ($current === $to || ! $current instanceof ReturnStatus) {
            return false;
        }

        return in_array($to, $current->transitionsFor((int) ($this->getAttribute('workflow_version') ?? 1)), true);
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
