<?php

namespace App\Domain\Order\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Order\Enums\OrderPaymentState;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * An account's own wholesale orders, as its people may see them (§10.2, P4-12).
 *
 * **Self-scoped by construction** (§31.3): every read starts from the signed-in
 * person's own business account and the ERP wholesale source, so an order
 * reference belonging to anybody else finds nothing rather than something to
 * refuse.
 *
 * **Only what the buyer should read.** What was bought and for how much, where
 * it goes, where the payment and the stock stand, and the timeline with the
 * notes written for the account. Never an internal note, the reason staff
 * recorded, who on the platform made a change, a warehouse, a cost or anybody's
 * allocation.
 */
class WholesaleOrderTracking
{
    public const PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(BusinessAccount $account): LengthAwarePaginator
    {
        return $this->ownedBy($account)
            ->with('payment')
            ->withCount('items')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function find(BusinessAccount $account, string $publicId): ?Order
    {
        return $this->ownedBy($account)
            ->where('public_id', $publicId)
            ->with(['items.stockReservation', 'payment.invoice', 'statusHistory', 'placedBy'])
            ->first();
    }

    /**
     * Whether the account has any wholesale order to look back on.
     */
    public function exists(BusinessAccount $account): bool
    {
        return $this->ownedBy($account)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'status_tone' => $order->status->tone(),
            'payment_state' => OrderPaymentState::of($order->payment)?->value,
            'placed_at' => $order->placed_at->toIso8601String(),
            'total' => $order->total->jsonSerialize(),
            'item_count' => (int) ($order->getAttribute('items_count') ?? $order->items->count()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Order $order): array
    {
        $payment = $order->payment;
        $invoice = $payment?->invoice;

        return [
            ...$this->summary($order),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'placed_by' => $order->placedBy?->name,
            'lines' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->public_id,
                'name' => $item->product_name,
                'sku' => $item->sku,
                'variant' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->jsonSerialize(),
                'subtotal' => $item->line_subtotal->jsonSerialize(),
                'discount' => $item->discount->jsonSerialize(),
                'tax' => $item->tax->jsonSerialize(),
                'total' => $item->line_total->jsonSerialize(),
            ])->all(),
            'totals' => [
                'subtotal' => $order->subtotal->jsonSerialize(),
                'discount' => $order->discount->jsonSerialize(),
                'delivery' => $order->delivery->jsonSerialize(),
                'tax' => $order->tax->jsonSerialize(),
                'tax_included' => $order->tax_included->jsonSerialize(),
                'total' => $order->total->jsonSerialize(),
            ],
            'coupon_code' => $order->coupon_code,
            'customer_note' => $order->customer_note,
            'intended_resale_channel' => $order->intended_resale_channel?->value,
            'addresses' => [
                'billing' => $order->billing_address,
                'shipping' => $order->shipping_address,
            ],
            'payment' => $payment === null ? null : [
                'reference' => $payment->reference,
                'gateway' => $this->gatewayLabel($payment->gateway),
                'expires_at' => $payment->expires_at?->toIso8601String(),
                // Decided here, not by the browser's clock.
                'window_open' => in_array($payment->status, PaymentStatus::open(), true)
                    && $payment->expires_at !== null
                    && $payment->expires_at->isFuture(),
            ],
            'stock' => $this->stock($order),
            'timeline' => $order->statusHistory->map(fn (OrderStatusChange $change) => [
                'status' => $change->new_status->value,
                'at' => $change->changed_at->toIso8601String(),
                'note' => $this->publicNote($change->public_note),
            ])->all(),
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->public_id,
                'number' => $invoice->number,
            ],
        ];
    }

    /**
     * Where the central stock held for this order stands.
     *
     * Every line's reservation together: held until a time, committed to the
     * order, given back, or — when the lines disagree — something a person is
     * looking at. No warehouse or allocation is named.
     *
     * @return array{state: string, held_until: string|null}
     */
    protected function stock(Order $order): array
    {
        $reservations = $order->items->map(fn (OrderItem $item) => $item->stockReservation)->filter();

        if ($reservations->isEmpty()) {
            return ['state' => 'released', 'held_until' => null];
        }

        $statuses = $reservations->map(fn ($reservation) => $reservation->status)->unique()->values();

        $state = match (true) {
            $statuses->every(fn ($status) => $status === StockReservationStatus::Active) => 'held',
            $statuses->every(fn ($status) => $status === StockReservationStatus::Committed) => 'committed',
            $statuses->every(fn ($status) => in_array($status, [StockReservationStatus::Released, StockReservationStatus::Expired], true)) => 'released',
            default => 'attention',
        };

        // Held for payment until the payment window closes, which is a little
        // before the reservations themselves run out.
        $deadline = $order->payment === null ? null : $order->payment->expires_at;
        $until = $deadline ?? $reservations->min('expires_at');

        return [
            'state' => $state,
            'held_until' => $state === 'held' ? $until?->toIso8601String() : null,
        ];
    }

    /**
     * A note the platform wrote is kept as a translation key and read in the
     * viewer's language; a note a person wrote is shown as they wrote it.
     */
    protected function publicNote(?string $note): ?string
    {
        if ($note === null || ! str_starts_with($note, 'orders.notes.')) {
            return $note;
        }

        $translated = __($note);

        return is_string($translated) ? $translated : $note;
    }

    protected function gatewayLabel(?string $gateway): ?string
    {
        if ($gateway === null) {
            return null;
        }

        $label = config("payment.gateways.{$gateway}.label");

        return is_string($label) && $label !== '' ? $label : $gateway;
    }

    /**
     * @return Builder<Order>
     */
    protected function ownedBy(BusinessAccount $account): Builder
    {
        return Order::query()
            ->where('business_account_id', $account->id)
            ->where('source', OrderSource::ErpWholesale);
    }
}
