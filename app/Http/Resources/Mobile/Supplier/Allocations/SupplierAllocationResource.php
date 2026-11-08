<?php

namespace App\Http\Resources\Mobile\Supplier\Allocations;

use App\Domain\Order\Models\OrderItem;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order line allocated to the Supplier (D25, P13-21), as the list shows it.
 *
 * Only what the Supplier may see: its own rate and the line's payable, never
 * the Platform Rate, the margin or the buyer.
 *
 * @mixin OrderItem
 */
class SupplierAllocationResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Public identifier (ULID). */
            'id' => $this->public_id,
            'order_reference' => $this->order->reference,
            'order_status' => $this->order->status->value,
            'placed_at' => $this->order->placed_at->toIso8601String(),
            'product_name' => $this->product_name,
            /** Public id of the variation, when there is one. */
            'variant' => $this->variant?->public_id,
            'quantity' => $this->supplier_allocated_quantity,
            'supplier_rate' => MoneyResource::nullable($this->supplier_rate),
            'allocated_at' => $this->supplier_allocated_at?->toIso8601String(),
            'payable' => $this->payable(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function payable(): ?array
    {
        $payable = $this->supplierPayable;

        if ($payable === null) {
            return null;
        }

        return [
            'id' => $payable->public_id,
            'reference' => $payable->reference,
            'status' => $payable->status->value,
            'status_label' => $payable->status->label(),
            'status_tone' => $payable->status->tone(),
            'gross_amount' => new MoneyResource($payable->gross_amount),
            'net_amount' => new MoneyResource($payable->netAmount()),
            'delivered_at' => $payable->delivered_at?->toIso8601String(),
            'payment_settled_at' => $payable->payment_settled_at?->toIso8601String(),
            'eligible_at' => $payable->eligible_at?->toIso8601String(),
        ];
    }
}
