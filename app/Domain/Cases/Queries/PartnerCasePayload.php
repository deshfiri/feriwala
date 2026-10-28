<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Support\Money\Money;

/**
 * A return or an exchange as the Partner whose it is reads it (D35).
 *
 * **Plain language, and only their own.** The status is said as what is
 * happening to them — "send the item back", "being checked", "settling" — not
 * as an internal stage, and every case says what the Partner has to do next, if
 * anything. The figures are their own financial effects, worked out here; where
 * Banij bears a cost the Partner is told it was covered and shown no amount of
 * Banij's accounting. There is no warehouse, no stock figure, no disposition, no
 * staff note, no staff name, and nothing about the customer beyond what the order
 * already shows them.
 */
class PartnerCasePayload
{
    public function __construct(
        protected CaseFinancials $financials,
        protected CaseTimeline $timeline,
        protected EvidencePayload $evidence,
        protected ProductImage $images,
        protected CaseLinks $links,
    ) {}

    /**
     * One row of the Partner's Returns & Exchanges list.
     *
     * @return array<string, mixed>
     */
    public function row(OrderReturn $case): array
    {
        $case->loadMissing(['order:id,public_id,reference', 'exchangeOrder:id,public_id,reference', 'items.orderItem']);

        $first = $case->items->first();
        $kind = $case->isExchange() ? 'exchange' : 'return';
        $charge = $this->charge($case);

        return [
            'id' => $case->public_id,
            'kind' => $kind,
            'kind_label' => (string) __('cases.kinds.'.$kind),
            'reference' => $case->reference,
            'order' => ['reference' => $case->order->reference, 'id' => $case->order->public_id],
            'exchange_order' => $case->exchangeOrder === null ? null : [
                'reference' => $case->exchangeOrder->reference,
                'id' => $case->exchangeOrder->public_id,
            ],
            'product' => $first === null ? null : [
                'name' => $first->orderItem->product_name,
                'variant' => $first->orderItem->variant_label,
                'more' => max(0, $case->items->count() - 1),
            ],
            'quantity' => (int) $case->items->sum('quantity'),
            'status' => $this->plainStatus($case),
            'status_label' => (string) __('cases.partner.status.'.$this->plainStatus($case)),
            'status_tone' => $this->tone($case->status),
            'responsibility' => $case->responsible_party?->value,
            'responsibility_label' => $case->responsible_party === null ? null : (string) __('cases.partner.responsibility.'.$case->responsible_party->value),
            'charge' => $charge['amount'],
            'charge_covered_by_banij' => $charge['covered'],
            'charge_is_final' => $charge['final'],
            'refund' => $case->refund_amount?->jsonSerialize(),
            'updated_at' => $case->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One case, in full.
     *
     * @return array<string, mixed>
     */
    public function detail(OrderReturn $case): array
    {
        $case->loadMissing([
            'order:id,public_id,reference,source', 'exchangeOrder.items', 'exchangeOrder:id,public_id,reference,status',
            'previousExchange:id,exchange_number', 'items.orderItem.product', 'evidenceFiles.file', 'refundRequest',
        ]);

        $replacements = $case->exchangeOrder?->items->keyBy('replaces_order_item_id');
        $row = $this->row($case);
        $financials = $this->financials->forReturn($case);

        return [
            'case' => [
                ...$row,
                'reason_label' => $case->reason->label(),
                'description' => $case->customer_note,
                'created_at' => $case->requested_at->toIso8601String(),
                'next_step' => $this->nextStep($case),
                'can_cancel' => $case->status->isOpen() && $case->usesCaseWorkflow(),
                'links' => $this->links->forPartner($case),
                'legacy' => ! $case->usesCaseWorkflow(),
            ],
            'items' => $case->items->map(fn (OrderReturnItem $line) => [
                'id' => $line->public_id,
                'name' => $line->orderItem->product_name,
                'variant' => $line->orderItem->variant_label,
                'sku' => $line->orderItem->sku,
                'image' => $this->images->for($line->orderItem),
                'requested' => $line->quantity,
                'approved' => $line->approved_quantity,
                'received' => $line->received_quantity,
                'replaced_with' => $replacements?->get($line->orderItem->id) === null ? null : [
                    'name' => $replacements->get($line->orderItem->id)->product_name,
                    'quantity' => $replacements->get($line->orderItem->id)->quantity,
                ],
            ])->values()->all(),
            'evidence' => $case->evidenceFiles->filter(fn ($file) => ! $file->isDraft())->map(fn ($file) => $this->evidence->for($file))->values()->all(),
            'legacy_evidence' => $this->evidence->legacy($case->evidence),
            'financials' => $financials,
            'refund' => [
                'amount' => $case->refund_amount?->jsonSerialize(),
                'state_label' => (string) __('cases.partner.refund_states.'.$case->refund_state->value),
                'refunded_at' => $case->refunded_at?->toIso8601String(),
                // Whether the customer is owed something is the Partner's own commercial matter with them.
                'is_a_matter_for_you' => $case->refund_amount !== null,
            ],
            'timeline' => $this->timeline->forReturn($case, false),
        ];
    }

    /**
     * What the Partner is charged for this case, and whether that is settled.
     *
     * @return array{amount: array<string, mixed>|null, covered: bool, final: bool}
     */
    protected function charge(OrderReturn $case): array
    {
        if ($case->responsible_party === ReturnParty::Banij) {
            return ['amount' => null, 'covered' => true, 'final' => true];
        }

        $total = null;

        foreach ([$case->return_charge, $case->isExchange() ? $case->exchange_delivery_charge : null] as $part) {
            if ($part instanceof Money) {
                $total = $total === null ? $part : $total->plus($part);
            }
        }

        $final = in_array($case->status, [ReturnStatus::SettlementPending, ReturnStatus::Completed], true);

        return ['amount' => $total?->jsonSerialize(), 'covered' => false, 'final' => $final];
    }

    /**
     * The state a Partner is told, in the fewest words.
     */
    protected function plainStatus(OrderReturn $case): string
    {
        return match ($case->status) {
            ReturnStatus::Requested => 'requested',
            ReturnStatus::Approved, ReturnStatus::WaitingForParcel => 'send_back',
            ReturnStatus::Received, ReturnStatus::ParcelReceived, ReturnStatus::UnderInspection => 'being_checked',
            ReturnStatus::InspectionApproved, ReturnStatus::SettlementPending, ReturnStatus::Refunded => 'settling',
            ReturnStatus::Completed => 'completed',
            ReturnStatus::Rejected, ReturnStatus::InspectionRejected => 'not_accepted',
            ReturnStatus::Cancelled => 'withdrawn',
        };
    }

    protected function tone(ReturnStatus $status): string
    {
        return $status->tone();
    }

    /**
     * What the Partner has to do, or are waiting for.
     *
     * @return array{key: string, label: string, action_needed: bool}
     */
    protected function nextStep(OrderReturn $case): array
    {
        $key = match ($case->status) {
            ReturnStatus::Requested => 'wait_for_review',
            ReturnStatus::Approved, ReturnStatus::WaitingForParcel => 'send_item_back',
            ReturnStatus::Received, ReturnStatus::ParcelReceived, ReturnStatus::UnderInspection => 'wait_for_check',
            ReturnStatus::InspectionApproved, ReturnStatus::SettlementPending, ReturnStatus::Refunded => 'wait_for_settlement',
            ReturnStatus::Completed => 'done',
            ReturnStatus::Rejected, ReturnStatus::InspectionRejected => 'not_accepted',
            ReturnStatus::Cancelled => 'withdrawn',
        };

        return [
            'key' => $key,
            'label' => (string) __('cases.partner.next.'.$key),
            'action_needed' => $key === 'send_item_back',
        ];
    }

    /**
     * Every case on an order, as rows.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(Order $order): array
    {
        return array_values(OrderReturn::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OrderReturn $case) => $this->row($case))
            ->all());
    }
}
