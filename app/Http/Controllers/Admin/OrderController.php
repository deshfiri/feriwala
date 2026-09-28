<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Actions\CancelUnpaidOrderByStaff;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusChange;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Order\Queries\CodConfirmationState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's review of orders (§18.4, §18.5, P4-12).
 *
 * Reading every order is `order.view`; cancelling one nobody has paid for is
 * `order.edit`. Both are platform permissions the `Gate::before` refuses to a
 * business identity, so owning an order is never a way into this screen — the
 * account follows its own orders on its own page, without the staff notes shown
 * here.
 *
 * Held orders and payments waiting on reconciliation are shown for what they are,
 * with the reason recorded. Resolving them — finding the stock again or refunding —
 * belongs to the fulfilment and refund work that follows; nothing here pretends to.
 */
class OrderController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected CancelUnpaidOrderByStaff $cancel,
        protected CodConfirmationState $confirmations,
        protected AllocationSourceCandidates $candidates,
        protected AllocateOrderLineSource $allocate,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        $filters = $this->filters($request);
        $term = $filters['search'];

        $orders = Order::query()
            ->with(['businessAccount:id,name', 'payment:id,status', 'website:id,name'])
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['source'] !== null, fn (Builder $query) => $query->where('source', $filters['source']))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'ilike', "%{$term}%")
                ->orWhere('storefront_order_reference', 'ilike', "%{$term}%")
                ->orWhereHas('businessAccount', fn (Builder $account) => $account->where('name', 'ilike', "%{$term}%"))
                ->orWhereHas('website', fn (Builder $website) => $website->where('name', 'ilike', "%{$term}%"))))
            // What somebody has to act on first: held orders, then waiting ones.
            ->orderByRaw("CASE status WHEN 'on_hold' THEN 0 WHEN 'payment_pending' THEN 1 ELSE 2 END")
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'id' => $order->public_id,
                'reference' => $order->reference,
                'account' => $order->businessAccount->name,
                'source' => $order->source->value,
                // Which shop took it, for an order that came through one (§18.5).
                'website' => $order->website?->name,
                'storefront_reference' => $order->storefront_order_reference,
                'status' => $order->status->value,
                'status_tone' => $order->status->tone(),
                'payment_status' => $order->payment?->status->value,
                'needs_attention' => $order->status === OrderStatus::OnHold
                    || ($order->payment?->status->needsReconciliation() ?? false),
                'total' => $order->total->jsonSerialize(),
                'placed_at' => $order->placed_at->toIso8601String(),
            ]);

        return Inertia::render('admin/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases()),
            'sources' => array_map(fn (OrderSource $source) => $source->value, OrderSource::cases()),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        $record = $this->order($order);
        $record->load(['businessAccount:id,name', 'placedBy:id,name', 'items.stockReservation', 'items.activeAllocation.warehouse', 'items.activeAllocation.supplier', 'payment.invoice', 'statusHistory.changedBy:id,name', 'website:id,public_id,name,subdomain', 'websiteCustomer:id,public_id,mobile,is_guest']);

        $payment = $record->payment;
        $canAllocate = $actor->can('transition', $record);
        $canViewSupplierPricing = $actor->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View));
        $canViewCatalogPricing = $actor->can(PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::View));

        return Inertia::render('admin/orders/show', [
            'order' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'source' => $record->source->value,
                'status' => $record->status->value,
                'status_tone' => $record->status->tone(),
                'account' => $record->businessAccount->name,
                'placed_by' => $record->placedBy?->name,
                /*
                 * The shop it came through, and the customer as that shop's
                 * own record knows them (§18.5). The snapshot below is what
                 * the order says; this is who to look up.
                 */
                'website' => $record->website === null ? null : [
                    'id' => $record->website->public_id,
                    'name' => $record->website->name,
                ],
                'storefront_reference' => $record->storefront_order_reference,
                'website_customer' => $record->websiteCustomer === null ? null : [
                    'id' => $record->websiteCustomer->public_id,
                    'mobile' => $record->websiteCustomer->mobile,
                    'is_guest' => $record->websiteCustomer->is_guest,
                ],
                'placed_at' => $record->placed_at->toIso8601String(),
                'paid_at' => $record->paid_at?->toIso8601String(),
                'cancelled_at' => $record->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $record->cancellation_reason,
                'held_at' => $record->held_at?->toIso8601String(),
                'hold_reason' => $record->hold_reason,
                'customer' => $record->customer,
                'addresses' => [
                    'billing' => $record->billing_address,
                    'shipping' => $record->shipping_address,
                ],
                'customer_note' => $record->customer_note,
                'intended_resale_channel' => $record->intended_resale_channel?->value,
                'coupon_code' => $record->coupon_code,
                'totals' => [
                    'subtotal' => $record->subtotal->jsonSerialize(),
                    'discount' => $record->discount->jsonSerialize(),
                    'delivery' => $record->delivery->jsonSerialize(),
                    'tax' => $record->tax->jsonSerialize(),
                    'tax_included' => $record->tax_included->jsonSerialize(),
                    'total' => $record->total->jsonSerialize(),
                ],
                'lines' => $record->items->map(fn (OrderItem $item) => [
                    'id' => $item->public_id,
                    'name' => $item->product_name,
                    'sku' => $item->sku,
                    'variant' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price->jsonSerialize(),
                    'discount' => $item->discount->jsonSerialize(),
                    'tax' => $item->tax->jsonSerialize(),
                    'total' => $item->line_total->jsonSerialize(),
                    'reservation' => $item->stockReservation === null ? null : [
                        'reference' => $item->stockReservation->reference,
                        'status' => $item->stockReservation->status->value,
                        'expires_at' => $item->stockReservation->expires_at->toIso8601String(),
                    ],
                    /*
                     * The staff-chosen source currently holding this line
                     * (AllocateOrderLineSource) — never spliced into a
                     * Client, Partner or Storefront payload (D25), and
                     * redacted here too, field by field, for staff who lack
                     * the permission each figure answers to: a Supplier's
                     * identity and Rate need supplier_pricing.view, the
                     * platform's own cost and margin need catalog.view.
                     * `order.view` alone (this action's own gate) is never
                     * enough to see any of the four.
                     */
                    'allocation' => $this->allocationSummary($item, $canViewSupplierPricing, $canViewCatalogPricing),
                    'can_allocate' => $canAllocate,
                ])->all(),
                'payment' => $payment === null ? null : [
                    'reference' => $payment->reference,
                    'status' => $payment->status->value,
                    'method' => $record->isCashOnDelivery() ? 'cod' : 'online',
                    'gateway' => $payment->gateway,
                    'gateway_mode' => $payment->gateway_mode,
                    'expires_at' => $payment->expires_at?->toIso8601String(),
                    'completed_at' => $payment->completed_at?->toIso8601String(),
                    'reconciliation_reason' => $payment->reconciliation_reason,
                ],
                'invoice' => $payment?->invoice === null ? null : ['number' => $payment->invoice->number],
                /*
                 * A cash-on-delivery order's confirmation (§6.2, P6-10): where
                 * it stands, until when, whether a code is outstanding and how
                 * many wrong guesses have been used — which is what answers
                 * "the customer says their code does not work". The code
                 * itself is held hashed and is readable by nobody.
                 */
                'confirmation' => $this->confirmations->for($record, forStaff: true),
                'history' => $record->statusHistory->map(fn (OrderStatusChange $change) => [
                    'previous_status' => $change->previous_status?->value,
                    'new_status' => $change->new_status->value,
                    'at' => $change->changed_at->toIso8601String(),
                    'source' => $change->source->value,
                    'actor' => $change->changedBy?->name,
                    'reason' => $change->reason,
                    'internal_note' => $change->internal_note,
                ])->all(),
            ],
            'can' => [
                // Waiting for a payment, or for a cash-on-delivery customer to
                // confirm: nobody has paid for either (§18.4, P6-10).
                'cancel' => $actor->can('transition', $record)
                    && in_array($record->status, [OrderStatus::PaymentPending, OrderStatus::CustomerVerificationPending], true)
                    && $payment !== null
                    && $payment->status !== PaymentStatus::Pending
                    && ! $payment->status->isSettled(),
            ],
        ]);
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.CancelUnpaidOrderByStaff::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $this->cancel->handle($actor, $record, $validated['reason']);
        } catch (OrderRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['reason' => __('orders.refused.busy')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.cancelled', ['reference' => $record->reference])]);

        return back();
    }

    /**
     * Every source that could fulfil one line — Central Warehouse and every
     * eligible Supplier offer for the exact variation — for the staff
     * allocation panel. The platform ranks nothing here; the panel sorts and
     * filters what this returns, never the query.
     */
    public function allocationCandidates(Request $request, string $order, string $item): JsonResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('viewAllocationSources', $record);

        $line = $this->line($record, $item);

        return response()->json([
            'candidates' => array_map(
                fn (AllocationCandidate $candidate) => $candidate->toArray(),
                $this->candidates->forLine($line),
            ),
        ]);
    }

    /**
     * Commit the line to the source a member of staff explicitly chose.
     * Never a fallback, never the cheapest — {@see AllocateOrderLineSource}.
     */
    public function allocate(Request $request, string $order, string $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $line = $this->line($record, $item);

        $validated = $request->validate([
            'source_type' => ['required', Rule::enum(AllocationSourceType::class)],
            'source_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $sourceType = AllocationSourceType::from($validated['source_type']);

        Gate::forUser($actor)->authorize('allocateSource', [$record, $sourceType]);

        try {
            $this->allocate->handle(
                $line,
                $sourceType,
                $validated['source_id'],
                $actor,
                $validated['reason'],
            );
        } catch (AllocationRefused $refused) {
            throw ValidationException::withMessages(['source_id' => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['source_id' => __('orders.refused.busy')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.allocated')]);

        return back();
    }

    /**
     * One line's current allocation, redacted field by field for a viewer
     * who lacks the permission each figure answers to.
     *
     * @return array<string, mixed>|null
     */
    protected function allocationSummary(OrderItem $item, bool $canViewSupplierPricing, bool $canViewCatalogPricing): ?array
    {
        $allocation = $item->activeAllocation;

        if ($allocation === null) {
            return null;
        }

        $isSupplierSourced = $allocation->source_type === AllocationSourceType::SupplierOffer;
        $canViewFinancials = $isSupplierSourced ? $canViewSupplierPricing : $canViewCatalogPricing;

        return [
            'id' => $allocation->public_id,
            'source_type' => $allocation->source_type->value,
            'source_type_label' => $allocation->source_type->label(),
            // A warehouse's name is not the sensitive part (only its cost
            // and margin are); a Supplier's identity is, so it is withheld
            // along with the rate rather than shown on its own.
            'source_label' => $isSupplierSourced
                ? ($canViewSupplierPricing ? $allocation->supplier?->business_name : null)
                : $allocation->warehouse?->name,
            'unit_cost' => $canViewFinancials ? $allocation->unit_cost->jsonSerialize() : null,
            'expected_margin' => $canViewFinancials ? $allocation->expected_margin->jsonSerialize() : null,
            'allocated_at' => $allocation->allocated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{search: string|null, status: string|null, source: string|null}
     */
    protected function filters(Request $request): array
    {
        $search = trim($request->string('search')->toString());

        return [
            'search' => $search === '' ? null : mb_substr($search, 0, 120),
            'status' => OrderStatus::tryFrom($request->string('status')->toString())?->value,
            'source' => OrderSource::tryFrom($request->string('source')->toString())?->value,
        ];
    }

    protected function order(string $publicId): Order
    {
        /** @var Order $order */
        $order = Order::query()->where('public_id', $publicId)->firstOrFail();

        return $order;
    }

    /**
     * A line, scoped to the order it must belong to — a mismatched pair in
     * the URL is a 404, never a chance to act on someone else's line (§31.3).
     */
    protected function line(Order $order, string $publicId): OrderItem
    {
        /** @var OrderItem $item */
        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $item;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
