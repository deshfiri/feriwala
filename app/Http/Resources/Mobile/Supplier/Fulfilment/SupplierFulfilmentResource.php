<?php

namespace App\Http\Resources\Mobile\Supplier\Fulfilment;

use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the Supplier's fulfilment commitments.
 *
 * Deliberately narrow: product, SKU, quantity, dates and status. Never the
 * buyer's identity or address.
 *
 * @mixin SupplierFulfilmentCommitment
 */
class SupplierFulfilmentResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offer = $this->offer;
        $item = $this->allocation->orderItem;

        // The verbs a Supplier sees for themselves ("decline", never "cancel"),
        // derived from the same transitionsTo() the web reads. `fail` is never
        // offered: a Supplier does not mark their own commitment Failed.
        $actionsByTransition = [
            FulfilmentCommitmentStatus::Confirmed->value => 'confirm',
            FulfilmentCommitmentStatus::Preparing->value => 'start_preparing',
            FulfilmentCommitmentStatus::Ready->value => 'mark_ready',
            FulfilmentCommitmentStatus::Cancelled->value => 'decline',
        ];

        return [
            /** Public identifier (ULID). */
            'id' => $this->public_id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'is_terminal' => $this->status->isTerminal(),
            'product_name' => $item->product_name,
            'sku' => $item->sku,
            'quantity' => $this->quantity,
            'supply_mode' => $offer->supply_mode->value,
            'supply_mode_label' => $offer->supply_mode->label(),
            'lead_time_days' => $offer->lead_time_days,
            'due_at' => $this->due_at?->toIso8601String(),
            'confirmation_due_at' => $this->confirmation_due_at?->toIso8601String(),
            /** When true, `confirm` needs `expected_ready_at`: nothing else fixes a date. */
            'needs_expected_ready_at' => $offer->supply_mode !== SupplyMode::ReadyStock
                && $offer->expected_availability_at === null
                && $offer->lead_time_days === null,
            'expected_ready_at' => $this->expected_ready_at?->toIso8601String(),
            'failed_reason' => $this->failed_reason,
            'cancelled_reason' => $this->cancelled_reason,
            /** The `action` values `advance` accepts right now. */
            'available_actions' => array_values(array_filter(array_map(
                fn (FulfilmentCommitmentStatus $to) => $actionsByTransition[$to->value] ?? null,
                $this->status->transitionsTo(),
            ))),
        ];
    }
}
