<?php

namespace App\Http\Resources\Mobile\Supplier\Payables;

use App\Domain\Supplier\Models\SupplierPayable;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What Feriwala owes the Supplier for one allocated order line (D25, P13-22).
 * Never the platform margin.
 *
 * @mixin SupplierPayable
 */
class SupplierPayableResource extends JsonResource
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
            'reference' => $this->reference,
            'order_reference' => $this->order->reference,
            'product_name' => $this->orderItem->product_name,
            'variant_label' => $this->orderItem->variant_label,
            'quantity' => $this->quantity,
            'supplier_rate' => new MoneyResource($this->supplier_rate),
            'gross_amount' => new MoneyResource($this->gross_amount),
            'net_amount' => new MoneyResource($this->netAmount()),
            /** One of `statuses[].value`. */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'payment_settled_at' => $this->payment_settled_at?->toIso8601String(),
            'eligible_at' => $this->eligible_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'settlement_reference' => $this->settlement_reference,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
