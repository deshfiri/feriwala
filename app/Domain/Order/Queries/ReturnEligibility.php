<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\ReturnTerms;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What of an order may still be sent back, and until when (§18.2, §26.3,
 * contract §6.3, P6-12).
 *
 * Decided from the order itself — its status, when it was delivered, what it
 * sold and what has already come back — and never from anything the asker
 * sends. A storefront naming a quantity is making a claim; this is the answer.
 *
 * The window runs from **delivery**, not from the order date: a customer who
 * waited three weeks for a parcel has not used up their return period waiting.
 * Until fulfilment records a delivery (§20, P6-17), no order reaches a state
 * from which anything can be returned, and this says so in those words.
 */
class ReturnEligibility
{
    public function __construct(
        protected ReturnTerms $terms,
    ) {}

    /**
     * Everything a screen or a storefront needs to show the customer.
     *
     * @return array{
     *     eligible: bool,
     *     refusal: string|null,
     *     window_closes_at: string|null,
     *     lines: array<int, array{
     *         id: string, sku: string, name: string, variant: string|null,
     *         sold: int, returned: int, returnable: int,
     *         unit_price: array<string, mixed>
     *     }>
     * }
     */
    public function for(Order $order): array
    {
        $order->loadMissing(['items']);

        $refusal = $this->refusalFor($order);
        $lines = [];

        foreach ($order->items as $item) {
            $returned = $this->claimedOn($item);

            $lines[] = [
                'id' => $item->public_id,
                'sku' => $item->sku,
                'name' => $item->product_name,
                'variant' => $item->variant_label,
                'sold' => $item->quantity,
                'returned' => $returned,
                'returnable' => max(0, $item->quantity - $returned),
                'unit_price' => $item->unit_price->jsonSerialize(),
            ];
        }

        $returnable = array_sum(array_column($lines, 'returnable'));

        if ($refusal === null && $returnable === 0) {
            $refusal = 'nothing_returnable';
        }

        return [
            'eligible' => $refusal === null,
            'refusal' => $refusal,
            'window_closes_at' => $this->windowClosesAt($order)?->toIso8601String(),
            'lines' => $lines,
        ];
    }

    /**
     * The same answer, thrown.
     *
     * @throws ReturnRefused
     */
    public function check(Order $order): void
    {
        $report = $this->for($order);

        if ($report['eligible']) {
            return;
        }

        throw match ($report['refusal']) {
            'return_window_closed' => ReturnRefused::windowClosed($report['window_closes_at']),
            'nothing_returnable' => ReturnRefused::nothingReturnable(),
            default => ReturnRefused::notDelivered(),
        };
    }

    /**
     * How many of this line are already spoken for by a live return.
     *
     * Approved quantities where a decision has been taken, asked-for quantities
     * where one has not: both hold the goods against a second request. A
     * rejected or cancelled return has given its claim up.
     */
    public function claimedOn(OrderItem $item): int
    {
        return (int) OrderReturnItem::query()
            ->where('order_item_id', $item->id)
            ->whereHas('orderReturn', fn (Builder $query) => $query->whereNotIn('status', [
                ReturnStatus::Rejected->value,
                ReturnStatus::Cancelled->value,
            ]))
            ->selectRaw('coalesce(sum(coalesce(approved_quantity, quantity)), 0) as claimed')
            ->value('claimed');
    }

    /**
     * When the door shuts, or null while it has not opened.
     */
    public function windowClosesAt(Order $order): ?CarbonImmutable
    {
        $delivered = $this->deliveredAt($order);

        return $delivered?->addDays($this->terms->windowDays());
    }

    /**
     * Why this order cannot be returned, or null when it can.
     */
    protected function refusalFor(Order $order): ?string
    {
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::ReturnRequested], true)) {
            return 'order_not_returnable';
        }

        $closes = $this->windowClosesAt($order);

        if ($closes !== null && $closes->isPast()) {
            return 'return_window_closed';
        }

        return null;
    }

    /**
     * When the order was delivered, from its own history.
     *
     * The status history is the record of what happened to the order, so the
     * date of delivery is read from the move that delivered it rather than
     * from a column somebody could set without the move.
     */
    protected function deliveredAt(Order $order): ?CarbonImmutable
    {
        $order->loadMissing('statusHistory');

        $delivered = $order->statusHistory
            ->first(fn ($change) => $change->new_status === OrderStatus::Delivered);

        return $delivered?->changed_at;
    }

    /**
     * The returns already made against this order, newest first.
     *
     * @return Collection<int, OrderReturn>
     */
    public function existingFor(Order $order): Collection
    {
        return OrderReturn::query()
            ->where('order_id', $order->id)
            ->with('items')
            ->orderByDesc('id')
            ->get();
    }
}
