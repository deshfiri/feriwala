<?php

namespace App\Domain\Damage\Queries;

use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Models\OrderDamageDisposition;
use App\Domain\Damage\Models\OrderDamageStatusChange;
use App\Domain\Order\Enums\ReturnDisposition;

/**
 * What a Partner is shown about one of their damages (D35).
 *
 * **Only safe, Partner-owned information.** The reference, the order's number,
 * the product and variation as sold, the quantity, the cause, the approved loss,
 * who bears it, where the units ended up, where the item is kept, where its
 * delivery request stands, and a timeline in plain words.
 *
 * Never: another Partner's anything, a customer's name, number or address, a
 * warehouse, a stock figure, the internal notes staff wrote, who inside Banij did
 * what, or the valuation's workings. The timeline carries only the notes written
 * for the Partner, as translation keys, with when.
 */
class DamagePayload
{
    /**
     * @return array<string, mixed>
     */
    public function forPartner(OrderDamage $damage): array
    {
        $damage->loadMissing(['order:id,reference', 'dispositions', 'dispatch', 'statusHistory']);

        return [
            'reference' => $damage->reference,
            'order_number' => $damage->order->reference,
            'product' => [
                'sku' => $damage->sku,
                'name' => $damage->product_name,
                'variation' => $damage->variant_label,
            ],
            'quantity' => $damage->quantity,
            'condition' => $damage->damage_condition->value,
            'condition_label' => $damage->damage_condition->label(),
            'cause' => $damage->cause?->value,
            'cause_label' => $damage->cause?->label(),
            'approved_loss' => $damage->approved_loss_amount?->jsonSerialize(),
            'responsibility' => $damage->responsible_party?->value,
            'responsibility_label' => $damage->responsible_party?->label(),
            'status' => $damage->status->value,
            'status_label' => $damage->status->label(),
            'dispositions' => $damage->dispositions->map(fn (OrderDamageDisposition $row) => [
                'disposition' => $row->disposition->value,
                'label' => $row->disposition->label(),
                'quantity' => $row->quantity,
            ])->values()->all(),
            'storage_state' => $this->storageState($damage),
            'delivery_request' => $this->deliveryRequest($damage),
            'can_request_delivery' => $this->canRequestDelivery($damage),
            'timeline' => $damage->statusHistory
                ->filter(fn (OrderDamageStatusChange $change) => $change->public_note !== null)
                ->map(fn (OrderDamageStatusChange $change) => [
                    'status' => $change->new_status->value,
                    'status_label' => $change->new_status->label(),
                    'note' => $change->public_note,
                    'at' => $change->changed_at->toIso8601String(),
                ])->values()->all(),
            'reported_at' => $damage->reported_at->toIso8601String(),
        ];
    }

    /**
     * Where the item physically is, in words that mean something to a Partner.
     * Damaged units are never sellable, whichever state this says.
     */
    public function storageState(OrderDamage $damage): string
    {
        if (! $damage->in_custody) {
            return 'not_held';
        }

        return match ($damage->status) {
            DamageStatus::Dispatched => 'in_transit',
            DamageStatus::ReceivedByPartner => 'with_partner',
            DamageStatus::DispatchRequested => 'awaiting_dispatch',
            DamageStatus::Finalized => $damage->dispositions->contains(
                fn (OrderDamageDisposition $row) => $row->disposition === ReturnDisposition::Damaged,
            ) ? 'held_by_banij' : 'not_held',
            default => 'held_for_inspection',
        };
    }

    /**
     * @return array{state: string, status: string|null, delivery_charge: array<string, mixed>|null, tracking_number: string|null, requested_at: string|null, dispatched_at: string|null, received_at: string|null}
     */
    public function deliveryRequest(OrderDamage $damage): array
    {
        $dispatch = $damage->dispatch;

        if ($dispatch === null) {
            return [
                'state' => $this->canRequestDelivery($damage) ? 'available' : 'not_available',
                'status' => null,
                'delivery_charge' => null,
                'tracking_number' => null,
                'requested_at' => null,
                'dispatched_at' => null,
                'received_at' => null,
            ];
        }

        return [
            'state' => match ($dispatch->status) {
                DamageDispatchStatus::Requested => 'requested',
                DamageDispatchStatus::Dispatched => 'dispatched',
                DamageDispatchStatus::ReceivedByPartner => 'delivered',
            },
            'status' => $dispatch->status->value,
            'delivery_charge' => $dispatch->delivery_charge->jsonSerialize(),
            'tracking_number' => $dispatch->tracking_number,
            'requested_at' => $dispatch->requested_at->toIso8601String(),
            'dispatched_at' => $dispatch->dispatched_at?->toIso8601String(),
            'received_at' => $dispatch->received_at?->toIso8601String(),
        ];
    }

    /**
     * Whether a delivery can be requested for this item right now.
     */
    public function canRequestDelivery(OrderDamage $damage): bool
    {
        return $damage->status === DamageStatus::Finalized
            && $damage->in_custody
            && $damage->dispatch === null
            && $damage->dispositions->contains(
                fn (OrderDamageDisposition $row) => $row->disposition === ReturnDisposition::Damaged,
            );
    }
}
