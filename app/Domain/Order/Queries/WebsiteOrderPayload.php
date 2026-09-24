<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Order\Enums\OrderPaymentState;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderStatusChange;

/**
 * A website order as its storefront reads it back (contract §5.3, §6.1.2).
 *
 * What an order-tracking page needs: where the order stands, what was bought
 * and for how much, how long the stock is held, and the customer-facing
 * timeline — the public notes only, never a staff note or a hold's reason.
 *
 * Nothing of Feriwala's side: no cost, no wholesale price, no margin, no
 * warehouse, no reservation beyond when it runs out (D12).
 */
class WebsiteOrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public function for(Order $order, ?string $redirectUrl = null): array
    {
        $order->loadMissing(['items.stockReservation', 'payment', 'statusHistory']);
        $payment = $order->payment;

        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'storefront_order_reference' => $order->storefront_order_reference,
            'status' => $order->status->value,
            'fulfillment_status' => $order->fulfillment_status->value,
            'courier_status' => $order->courier_status->value,
            'delivery_status' => $order->delivery_status->value,
            'tracking_number' => null,
            'placed_at' => $order->placed_at->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'updated_at' => $order->updated_at->toIso8601String(),
            'totals' => [
                'subtotal' => $order->subtotal->jsonSerialize(),
                'discount' => $order->discount->jsonSerialize(),
                'shipping' => $order->delivery->jsonSerialize(),
                'tax' => $order->tax->jsonSerialize(),
                'tax_included' => $order->tax_included->jsonSerialize(),
                'grand_total' => $order->total->jsonSerialize(),
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'sku' => $item->sku,
                'name' => $item->product_name,
                'variant' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->jsonSerialize(),
                'line_total' => $item->line_total->jsonSerialize(),
            ])->all(),
            'payment' => [
                'method' => $order->isCashOnDelivery() ? 'cod' : 'online',
                'state' => OrderPaymentState::of($payment)?->value,
                'gateway' => $payment?->gateway,
                'expires_at' => $payment?->expires_at?->toIso8601String(),
                'redirect_url' => $redirectUrl,
            ],
            'stock_reservation' => $this->reservation($order),
            'confirmation' => $this->confirmation($order),
            'returnable' => $this->returnable($order),
            'returns' => app(ReturnEligibility::class)->existingFor($order)
                ->map(fn (OrderReturn $return) => app(ReturnPayload::class)->for($return))
                ->values()
                ->all(),
            'timeline' => $order->statusHistory
                ->filter(fn (OrderStatusChange $change) => $change->public_note !== null)
                ->map(fn (OrderStatusChange $change) => [
                    'status' => $change->new_status->value,
                    'at' => $change->changed_at->toIso8601String(),
                    // In English: a storefront shows its customer its own words,
                    // and this is the plain account of what happened.
                    'note' => (string) __($change->public_note, [], 'en'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * What the customer may still send back, and until when (contract §6.3).
     *
     * Per SKU, net of what earlier returns already claim, so a returns page
     * never offers a quantity the ERP would refuse.
     *
     * @return array{eligible: bool, reason: string|null, window_closes_at: string|null, lines: array<int, array{sku: string, returnable: int}>}
     */
    protected function returnable(Order $order): array
    {
        $report = app(ReturnEligibility::class)->for($order);

        return [
            'eligible' => $report['eligible'],
            'reason' => $report['refusal'],
            'window_closes_at' => $report['window_closes_at'],
            'lines' => array_map(fn (array $line) => [
                'sku' => $line['sku'],
                'returnable' => $report['eligible'] ? $line['returnable'] : 0,
            ], $report['lines']),
        ];
    }

    /**
     * When the stock held for this order runs out, while it is still held.
     *
     * @return array{expires_at: string}|null
     */
    protected function reservation(Order $order): ?array
    {
        $expiry = $order->items
            ->map(fn (OrderItem $item) => $item->stockReservation)
            ->filter(fn (mixed $reservation) => $reservation !== null && $reservation->status === StockReservationStatus::Active)
            ->map(fn (mixed $reservation) => $reservation->expires_at)
            ->min();

        return $expiry === null ? null : ['expires_at' => $expiry->toIso8601String()];
    }

    /**
     * Where a cash-on-delivery order's confirmation stands (§6.1.3, §6.2).
     *
     * Whether it is still waiting, until when, and how long before another
     * code may be asked for — **never the code**, and never how many guesses
     * are left, which would tell somebody guessing how close they are. The
     * three fields the contract names, from the one place that decides them.
     *
     * @return array{state: string, expires_at: string|null, resend_available_in: int}|null
     */
    protected function confirmation(Order $order): ?array
    {
        $state = app(CodConfirmationState::class)->for($order);

        return $state === null ? null : [
            'state' => $state['state'],
            'expires_at' => $state['expires_at'],
            'resend_available_in' => $state['resend_available_in'],
        ];
    }
}
