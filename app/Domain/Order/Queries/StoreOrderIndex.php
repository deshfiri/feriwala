<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Store Orders list, for staff (every order) or for one Partner (only theirs).
 *
 * The scope is a query concern: a Partner's list is built from their own account
 * and nothing in the request can widen it, so another Partner's order is not
 * reachable by a filter, a URL or a changed parameter (§31.3). Staff may narrow
 * by Partner and Storefront as well.
 */
class StoreOrderIndex
{
    public const PER_PAGE = 25;

    /**
     * @return Builder<Order>
     */
    public function query(Request $request, ?int $accountId = null): Builder
    {
        $query = Order::query()
            ->where('source', OrderSource::Website->value)
            ->with(['businessAccount:id,public_id,name', 'website:id,public_id,name,subdomain', 'payment:id,status'])
            ->when($accountId !== null, fn (Builder $q) => $q->where('business_account_id', $accountId));

        if ($accountId === null && $request->filled('partner')) {
            $query->whereHas('businessAccount', fn (Builder $q) => $q->where('public_id', $request->string('partner')->toString()));
        }

        if ($request->filled('storefront')) {
            $query->whereHas('website', fn (Builder $q) => $q->where('public_id', $request->string('storefront')->toString()));
        }

        if ($request->filled('step') && ($step = StoreOrderStep::tryFrom($request->string('step')->toString())) !== null) {
            $query->whereIn('status', collect(OrderStatus::cases())
                ->filter(fn (OrderStatus $status) => StoreOrderStep::for($status) === $step)
                ->map(fn (OrderStatus $status) => $status->value)
                ->all());
        }

        if ($request->filled('payment')) {
            $query->whereHas('payment', fn (Builder $q) => $q->where('status', $request->string('payment')->toString()));
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($request->filled($key) && strtotime($request->string($key)->toString()) !== false) {
                $day = CarbonImmutable::parse($request->string($key)->toString());
                $query->where('placed_at', $operator, $key === 'from' ? $day->startOfDay() : $day->endOfDay());
            }
        }

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $query->where(fn (Builder $q) => $q->where('reference', 'ilike', "%{$term}%")->orWhere('storefront_order_reference', 'ilike', "%{$term}%"));
        }

        return $query->orderByDesc('placed_at')->orderByDesc('id');
    }

    /**
     * One row of the list. The partner and storefront are the order's own
     * snapshot-time records, never a live lookup of anything else.
     *
     * @return array<string, mixed>
     */
    public function row(Order $order, bool $forStaff): array
    {
        $step = StoreOrderStep::for($order->status);

        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'storefront_reference' => $order->storefront_order_reference,
            'storefront' => ['id' => $order->website?->public_id, 'name' => $order->website?->name],
            'partner' => $forStaff ? ['id' => $order->businessAccount->public_id, 'name' => $order->businessAccount->name] : null,
            'customer' => $order->customer['name'] ?? null,
            'step' => $step?->value,
            'step_label' => $step?->label() ?? __('store_orders.cancelled'),
            'status' => $order->status->value,
            'fulfilment' => $order->fulfillment_status->value,
            'courier' => $order->courier_status->value,
            'delivery' => $order->delivery_status->value,
            'payment' => $order->payment?->status->value,
            'total' => $order->total->jsonSerialize(),
            'placed_at' => $order->placed_at->toIso8601String(),
        ];
    }
}
