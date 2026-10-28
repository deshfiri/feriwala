<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;

/**
 * How an Order, its return or exchange case, its Exchange Order and the exchanges
 * before and after it point at one another (D35).
 *
 * **A link is only given where it will open.** Each address is built for the
 * person reading, from what they may see: staff need `order.view` to be sent to
 * an Order and the case's own view permission to be sent to a case; a Partner is
 * sent only to their own Orders and cases, and the neighbours of a chain are
 * looked up inside the same account. Where a link would be refused the reference
 * is still shown, with no address, so nothing on a screen is a dead end.
 *
 * @phpstan-type Ref array{reference: string, url: string|null, status_label?: string|null}
 */
class CaseLinks
{
    /**
     * Everything a case links to, for a member of staff.
     *
     * @return array{order: Ref, exchange_order: Ref|null, previous_exchange: Ref|null, next_exchange: Ref|null}
     */
    public function forStaff(User $viewer, OrderReturn $case): array
    {
        $canOpenOrders = $viewer->can('viewAny', Order::class);
        $order = fn (Order $order) => $canOpenOrders ? route('admin.orders.show', $order->public_id) : null;
        $caseUrl = fn (OrderReturn $other) => $viewer->can(PermissionCatalogue::name(
            $other->isExchange() ? PermissionModule::Exchange : PermissionModule::ReturnCase,
            PermissionAction::View,
        )) ? route('admin.cases.returns.show', $other->public_id) : null;

        return $this->build($case, $order, $caseUrl, restrictToAccount: false);
    }

    /**
     * Everything a case links to, for the Partner it belongs to.
     *
     * @return array{order: Ref, exchange_order: Ref|null, previous_exchange: Ref|null, next_exchange: Ref|null}
     */
    public function forPartner(OrderReturn $case): array
    {
        return $this->build(
            $case,
            fn (Order $order) => $order->business_account_id === $case->business_account_id ? route('store-orders.show', $order->public_id) : null,
            fn (OrderReturn $other) => $other->business_account_id === $case->business_account_id ? route('cases.show', $other->public_id) : null,
            restrictToAccount: true,
        );
    }

    /**
     * Where an Exchange Order came from, for staff: the case that sent it and the
     * Order it replaces goods of. Null for any other Order.
     *
     * @return array{case: Ref, original_order: Ref|null}|null
     */
    public function exchangeOriginForStaff(User $viewer, Order $order): ?array
    {
        $case = $this->caseThatSent($order);

        if ($case === null) {
            return null;
        }

        $original = $case->order;

        return [
            'case' => [
                'reference' => (string) ($case->exchange_number ?? $case->reference),
                'url' => $viewer->can(PermissionCatalogue::name(PermissionModule::Exchange, PermissionAction::View))
                    ? route('admin.cases.returns.show', $case->public_id)
                    : null,
            ],
            'original_order' => [
                'reference' => $original->reference,
                'url' => $viewer->can('viewAny', Order::class) ? route('admin.orders.show', $original->public_id) : null,
            ],
        ];
    }

    /**
     * The same, for the Partner whose Exchange Order it is.
     *
     * @return array{case: Ref, original_order: Ref|null}|null
     */
    public function exchangeOriginForPartner(Order $order): ?array
    {
        $case = $this->caseThatSent($order);

        if ($case === null || $case->business_account_id !== $order->business_account_id) {
            return null;
        }

        $original = $case->order;

        return [
            'case' => [
                'reference' => (string) ($case->exchange_number ?? $case->reference),
                'url' => route('cases.show', $case->public_id),
            ],
            'original_order' => [
                'reference' => $original->reference,
                'url' => $original->business_account_id === $order->business_account_id ? route('store-orders.show', $original->public_id) : null,
            ],
        ];
    }

    protected function caseThatSent(Order $order): ?OrderReturn
    {
        return OrderReturn::query()
            ->with('order:id,public_id,reference,business_account_id')
            ->where('exchange_order_id', $order->id)
            ->first();
    }

    /**
     * @param  callable(Order): ?string  $orderUrl
     * @param  callable(OrderReturn): ?string  $caseUrl
     * @return array{order: Ref, exchange_order: Ref|null, previous_exchange: Ref|null, next_exchange: Ref|null}
     */
    protected function build(OrderReturn $case, callable $orderUrl, callable $caseUrl, bool $restrictToAccount): array
    {
        // Read fresh, whatever columns the caller loaded: a link needs the account and the public id.
        $orders = Order::query()
            ->whereIn('id', array_filter([$case->order_id, $case->exchange_order_id]))
            ->get(['id', 'public_id', 'reference', 'status', 'business_account_id'])
            ->keyBy('id');
        $order = $orders->get($case->order_id) ?? Order::query()->findOrFail($case->order_id);
        $exchangeOrder = $case->exchange_order_id === null ? null : $orders->get($case->exchange_order_id);
        $previous = $case->previous_exchange_id === null ? null : OrderReturn::query()->find($case->previous_exchange_id);

        $next = OrderReturn::query()
            ->where('previous_exchange_id', $case->id)
            ->when($restrictToAccount, fn ($query) => $query->where('business_account_id', $case->business_account_id))
            ->orderBy('id')
            ->first();

        $neighbour = fn (OrderReturn $other) => [
            'reference' => (string) ($other->exchange_number ?? $other->reference),
            'url' => $caseUrl($other),
        ];

        return [
            'order' => ['reference' => $order->reference, 'url' => $orderUrl($order)],
            'exchange_order' => $exchangeOrder === null ? null : [
                'reference' => $exchangeOrder->reference,
                'url' => $orderUrl($exchangeOrder),
                'status_label' => $exchangeOrder->status->label(),
            ],
            'previous_exchange' => $previous === null ? null : $neighbour($previous),
            'next_exchange' => $next === null ? null : $neighbour($next),
        ];
    }
}
