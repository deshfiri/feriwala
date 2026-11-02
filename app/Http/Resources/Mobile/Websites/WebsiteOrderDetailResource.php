<?php

namespace App\Http\Resources\Mobile\Websites;

use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentTrackingEvent;
use App\Domain\Order\Enums\OrderPaymentState;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusChange;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order in full, as the partner whose shop took it reads it.
 *
 * Their sale: who bought, where it goes, what it cost, where the payment stands
 * and how long the stock is held. Not the gateway's own identifiers, not a staff
 * note, not the reason Feriwala put an order on hold, not a courier label or
 * cost. Expects `items.stockReservation`, `payment`, `statusHistory` and
 * `shipments.courierProvider` / `shipments.trackingEvents` to be loaded.
 *
 * @mixin Order
 */
class WebsiteOrderDetailResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->payment;
        $customer = $this->customer;

        return [
            'id' => $this->public_id,
            'reference' => $this->reference,
            'storefront_reference' => $this->storefront_order_reference,
            'customer' => $customer['name'] ?? null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'payment_state' => OrderPaymentState::of($payment)?->value,
            'payment_method' => $this->isCashOnDelivery() ? 'cod' : 'online',
            'total' => new MoneyResource($this->total),
            'placed_at' => $this->placed_at->toIso8601String(),
            /** @var array{name: string|null, mobile: string|null, email: string|null, is_guest: bool} */
            'customer_details' => [
                'name' => $customer['name'] ?? null,
                'mobile' => $customer['mobile'] ?? null,
                'email' => $customer['email'] ?? null,
                'is_guest' => (bool) ($customer['is_guest'] ?? true),
            ],
            /** @var array<string, mixed>|null */
            'shipping_address' => $this->shipping_address,
            /** @var array<string, mixed>|null */
            'billing_address' => $this->billing_address,
            'customer_note' => $this->customer_note,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            /** The fulfilment, delivery and courier tracks, each as a label and a tone. */
            'tracks' => [
                'fulfillment' => ['label' => $this->fulfillment_status->label(), 'tone' => $this->fulfillment_status->tone()],
                'delivery' => ['label' => $this->delivery_status->label(), 'tone' => $this->delivery_status->tone()],
                'courier' => ['label' => $this->courier_status->label(), 'tone' => $this->courier_status->tone()],
            ],
            /** Each parcel: who carries it, its tracking number and what the courier has reported. */
            'shipments' => $this->shipments->map(fn (Shipment $shipment) => [
                'reference' => $shipment->reference,
                'courier' => $shipment->courierProvider->name,
                'tracking_number' => $shipment->tracking_number,
                'status_label' => $shipment->status->label(),
                'status_tone' => $shipment->status->tone(),
                'events' => $shipment->trackingEvents
                    ->sortBy('occurred_at')
                    ->map(fn (ShipmentTrackingEvent $event) => [
                        'code' => $event->event_code,
                        'description' => $event->description,
                        'at' => $event->occurred_at->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),
            'totals' => [
                'subtotal' => new MoneyResource($this->subtotal),
                'discount' => new MoneyResource($this->discount),
                'delivery' => new MoneyResource($this->delivery),
                'tax' => new MoneyResource($this->tax),
                'total' => new MoneyResource($this->total),
            ],
            'lines' => $this->items->map(fn (OrderItem $item) => [
                /** Public identifier (ULID). */
                'id' => $item->public_id,
                'name' => $item->product_name,
                'sku' => $item->sku,
                'variant' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => new MoneyResource($item->unit_price),
                'total' => new MoneyResource($item->line_total),
                /** How long the stock is held, and nothing about where it is. */
                'reservation' => $item->stockReservation === null ? null : [
                    'status' => $item->stockReservation->status->value,
                    'status_label' => __('inventory.reservation_statuses.'.$item->stockReservation->status->value),
                    'expires_at' => $item->stockReservation->expires_at->toIso8601String(),
                ],
            ])->values()->all(),
            'payment' => $payment === null ? null : [
                'state' => OrderPaymentState::of($payment)?->value,
                'method' => $this->isCashOnDelivery() ? 'cod' : 'online',
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'completed_at' => $payment->completed_at?->toIso8601String(),
            ],
            /** The customer's own account of what happened, as the storefront reads it. */
            'timeline' => $this->statusHistory
                ->filter(fn (OrderStatusChange $change) => $change->public_note !== null)
                ->map(fn (OrderStatusChange $change) => [
                    'status' => $change->new_status->value,
                    'status_label' => $change->new_status->label(),
                    'at' => $change->changed_at->toIso8601String(),
                    'note' => (string) __($change->public_note),
                ])
                ->values()
                ->all(),
        ];
    }
}
