<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\Models\OrderReturnStatusChange;

/**
 * A return as a storefront, or the partner who owns it, reads it back
 * (contract §5.3, §6.3, P6-12).
 *
 * Where it stands, what is coming back and how much of it was agreed and
 * received, where the money is, and the timeline in the words the customer is
 * given. **Nothing of how Feriwala keeps its stock**: no warehouse, no
 * disposition, no staff note, no refund request's internals (D12).
 */
class ReturnPayload
{
    /**
     * @param  string  $locale  the timeline's language: English for a storefront,
     *                          which shows its customer its own words; the
     *                          reader's own for the partner's screen
     * @return array<string, mixed>
     */
    public function for(OrderReturn $return, string $locale = 'en'): array
    {
        $return->loadMissing(['items.orderItem', 'statusHistory']);

        return [
            'id' => $return->public_id,
            'reference' => $return->reference,
            'status' => $return->status->value,
            'reason' => $return->reason->value,
            'customer_note' => $return->customer_note,
            'requested_at' => $return->requested_at->toIso8601String(),
            'decision_note' => in_array($return->status->value, ['approved', 'rejected', 'received', 'refunded'], true)
                ? $return->decision_note
                : null,
            'lines' => $return->items->map(fn (OrderReturnItem $item) => [
                'id' => $item->public_id,
                'sku' => $item->orderItem->sku,
                'name' => $item->orderItem->product_name,
                'quantity' => $item->quantity,
                'approved_quantity' => $item->approved_quantity,
                'received_quantity' => $item->received_quantity,
            ])->all(),
            'refund' => [
                'state' => $return->refund_state->value,
                'amount' => $return->refund_amount_minor?->jsonSerialize(),
                'refunded_at' => $return->refunded_at?->toIso8601String(),
            ],
            'timeline' => $return->statusHistory
                ->filter(fn (OrderReturnStatusChange $change) => $change->public_note !== null)
                ->map(fn (OrderReturnStatusChange $change) => [
                    'status' => $change->new_status->value,
                    'at' => $change->changed_at->toIso8601String(),
                    'note' => (string) __($change->public_note, [], $locale),
                ])
                ->values()
                ->all(),
        ];
    }
}
