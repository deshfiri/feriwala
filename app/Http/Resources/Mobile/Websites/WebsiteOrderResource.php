<?php

namespace App\Http\Resources\Mobile\Websites;

use App\Domain\Order\Enums\OrderPaymentState;
use App\Domain\Order\Models\Order;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order the partner's own website took, as a row in the list.
 *
 * Expects `payment` to be loaded.
 *
 * @mixin Order
 */
class WebsiteOrderResource extends JsonResource
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
            /** The reference the storefront itself gave the order. */
            'storefront_reference' => $this->storefront_order_reference,
            'customer' => $this->customer['name'] ?? null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'payment_state' => OrderPaymentState::of($this->payment)?->value,
            /** `cod` (paid on delivery) or `online`. */
            'payment_method' => $this->isCashOnDelivery() ? 'cod' : 'online',
            'total' => new MoneyResource($this->total),
            'placed_at' => $this->placed_at->toIso8601String(),
        ];
    }
}
