<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Actions\AdvanceOrderCourierStatus;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\Actions\AdvanceOrderFulfilmentStatus;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Actions\CancelUnpaidOrderByStaff;
use App\Domain\Order\Actions\ConfirmProductSourceLink;
use App\Domain\Order\Actions\EvaluateOrderProceedsEligibility;
use App\Domain\Order\Actions\RecordCodCollection;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderCourierStatusChange;
use App\Domain\Order\Models\OrderDeliveryStatusChange;
use App\Domain\Order\Models\OrderFulfillmentStatusChange;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Models\OrderStatusChange;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Order\Queries\CodConfirmationState;
use App\Domain\Order\Queries\SearchAllocationSources;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Supplier\Actions\AdvanceSupplierFulfilmentCommitment;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Http\Controllers\Controller;
use App\Integrations\Courier\CourierManager;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StateMachine\TransitionableState;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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
        protected SearchAllocationSources $search,
        protected ConfirmProductSourceLink $confirmLink,
        protected AdvanceSupplierFulfilmentCommitment $advanceCommitment,
        protected AdvanceOrderFulfilmentStatus $advanceFulfilment,
        protected AdvanceOrderDeliveryStatus $advanceDelivery,
        protected AdvanceOrderCourierStatus $advanceCourier,
        protected CourierManager $courier,
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
        $record->load([
            'businessAccount:id,name', 'placedBy:id,name', 'items.stockReservation',
            'items.activeAllocations.warehouse', 'items.activeAllocations.supplier', 'items.activeAllocations.offer',
            'items.activeAllocations.fulfilmentCommitment.statusHistory.changedBy:id,name',
            'items.activeAllocations.payable',
            'items.proceedsSettlement',
            'payment.invoice', 'statusHistory.changedBy:id,name', 'website:id,public_id,name,subdomain',
            'websiteCustomer:id,public_id,mobile,is_guest',
            'fulfillmentStatusHistory.changedBy:id,name',
            'deliveryStatusHistory.changedBy:id,name',
            'courierStatusHistory.changedBy:id,name',
            'shipments.courierProvider:id,code,name',
        ]);

        $payment = $record->payment;
        $canAllocate = $actor->can('transition', $record);
        $canViewSupplierPricing = $actor->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View));
        $canViewCatalogPricing = $actor->can(PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::View));
        $canManageFulfilmentCommitment = Gate::forUser($actor)->allows('manageFulfilmentCommitment', $record);
        $canOverrideFulfilmentState = Gate::forUser($actor)->allows('overrideFulfilmentState', $record);
        $canManageShipments = Gate::forUser($actor)->allows('manageShipments', Shipment::class);
        $canRecordCodCollection = Gate::forUser($actor)->allows('recordCodCollection', $record);

        return Inertia::render('admin/orders/show', [
            'order' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'source' => $record->source->value,
                'status' => $record->status->value,
                'status_tone' => $record->status->tone(),
                'account_type' => $record->account_type->value,
                'lifecycle' => [
                    'fulfillment' => $this->lifecycleSummary(
                        $record->fulfillment_status,
                        $record->fulfillmentStatusHistory,
                        $canAllocate,
                    ),
                    'delivery' => $this->lifecycleSummary(
                        $record->delivery_status,
                        $record->deliveryStatusHistory,
                        $canAllocate,
                    ),
                    'courier' => $this->lifecycleSummary(
                        $record->courier_status,
                        $record->courierStatusHistory,
                        $canAllocate,
                    ),
                ],
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
                    'resale_amount' => $item->resale_amount?->jsonSerialize(),
                    /*
                     * A Non-Conditional line's reseller-earning settlement
                     * (D-new) — two independent facts, `Delivered` and the
                     * COD cash actually collected, neither of which alone
                     * credits anything. Null until `Delivered` is reached
                     * once.
                     */
                    'proceeds' => $item->proceedsSettlement === null ? null : [
                        'resale_amount' => $item->proceedsSettlement->resale_amount->jsonSerialize(),
                        'recovered_amount' => $item->proceedsSettlement->recovered_amount->jsonSerialize(),
                        'delivered_at' => $item->proceedsSettlement->delivered_at?->toIso8601String(),
                        'cod_collected_at' => $item->proceedsSettlement->cod_collected_at?->toIso8601String(),
                        'cod_amount_collected' => $item->proceedsSettlement->cod_amount_collected?->jsonSerialize(),
                        'eligible_at' => $item->proceedsSettlement->eligible_at?->toIso8601String(),
                        'reseller_earning' => $item->proceedsSettlement->reseller_earning?->jsonSerialize(),
                        'flagged_for_review' => $item->proceedsSettlement->flagged_for_review,
                    ],
                    'can_record_cod_collection' => $canRecordCodCollection
                        && $record->isNonConditional()
                        && $item->proceedsSettlement?->cod_collected_at === null,
                    'reservation' => $item->stockReservation === null ? null : [
                        'reference' => $item->stockReservation->reference,
                        'status' => $item->stockReservation->status->value,
                        'expires_at' => $item->stockReservation->expires_at->toIso8601String(),
                    ],
                    /*
                     * The staff-chosen sources currently holding this line
                     * (AllocateOrderLineSource) — one row, or several when
                     * the line is split across sources (Advanced Order
                     * Management batch, Commit 3) — never spliced into a
                     * Client, Partner or Storefront payload (D25), and
                     * redacted here too, field by field, for staff who lack
                     * the permission each figure answers to: a Supplier's
                     * identity and Rate need supplier_pricing.view, the
                     * platform's own cost and margin need catalog.view.
                     * `order.view` alone (this action's own gate) is never
                     * enough to see any of the four.
                     */
                    'allocations' => $item->activeAllocations->map(
                        fn (OrderItemAllocation $allocation) => $this->allocationSummary($allocation, $canViewSupplierPricing, $canViewCatalogPricing, $canManageFulfilmentCommitment),
                    )->all(),
                    'allocated_quantity' => (int) $item->activeAllocations->sum('quantity'),
                    'remaining_quantity' => max(0, $item->quantity - (int) $item->activeAllocations->sum('quantity')),
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
                /*
                 * Every shipment raised for this order (Advanced Order
                 * Management batch, Commit 5) -- only ever shown to staff who
                 * can manage them, since the figure is "what it costs to
                 * ship" rather than any sensitive Supplier or margin figure,
                 * but there is nothing to act on here without the ability
                 * anyway.
                 */
                'shipments' => $canManageShipments
                    ? $record->shipments->map(fn (Shipment $shipment) => [
                        'id' => $shipment->public_id,
                        'reference' => $shipment->reference,
                        'provider' => $shipment->courierProvider->name,
                        'status' => $shipment->status->value,
                        'status_label' => $shipment->status->label(),
                        'status_tone' => $shipment->status->tone(),
                        'tracking_number' => $shipment->tracking_number,
                    ])->all()
                    : [],
                'courier_providers' => $canManageShipments ? $this->courier->catalogue() : [],
            ],
            'can' => [
                // Waiting for a payment, or for a cash-on-delivery customer to
                // confirm: nobody has paid for either (§18.4, P6-10).
                'cancel' => $actor->can('transition', $record)
                    && in_array($record->status, [OrderStatus::PaymentPending, OrderStatus::CustomerVerificationPending], true)
                    && $payment !== null
                    && $payment->status !== PaymentStatus::Pending
                    && ! $payment->status->isSettled(),
                'override_fulfilment_state' => $canOverrideFulfilmentState,
                'manage_shipments' => $canManageShipments,
                'record_cod_collection' => $canRecordCodCollection,
            ],
        ]);
    }

    /**
     * Staff confirm the cash actually collected for a Non-Conditional line's
     * COD delivery (D-new) — the authoritative event
     * {@see EvaluateOrderProceedsEligibility} waits on, alongside `Delivered`,
     * before a reseller's earning on that line becomes real.
     */
    public function recordCodCollection(Request $request, string $order, string $item, RecordCodCollection $record): RedirectResponse
    {
        $actor = $this->actor($request);
        $orderRecord = $this->order($order);

        Gate::forUser($actor)->authorize('recordCodCollection', $orderRecord);

        $line = $this->line($orderRecord, $item);

        $validated = $request->validate([
            'amount_collected' => ['required', new DecimalAmountRule],
        ]);

        try {
            $record->handle($actor, $line, DecimalAmount::parse($validated['amount_collected'], $line->unit_price->currency));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_collected' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.cod_collection_recorded')]);

        return back();
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

        // When browsing candidates to replace one specific existing split
        // (Advanced Order Management batch, Commit 3), that allocation's own
        // quantity is left out of "already allocated" -- otherwise a
        // like-for-like swap on a fully-allocated line would show zero
        // units remaining and every candidate as unable to cover the line.
        $replacing = $request->string('replacing')->toString();
        $excluding = $replacing === '' ? null : OrderItemAllocation::query()
            ->where('order_item_id', $line->id)
            ->where('public_id', $replacing)
            ->first();

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'source_type' => ['sometimes', 'nullable', Rule::enum(AllocationSourceType::class)],
            'availability' => ['sometimes', 'nullable', Rule::in(['available'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['cost_asc', 'cost_desc'])],
        ]);

        return response()->json([
            // The platform ranks nothing: these are the staff's own filters
            // and sort, applied to what the line's sourcing group allows.
            'candidates' => array_map(
                fn (AllocationCandidate $candidate) => $candidate->toArray(),
                $this->filterCandidates($this->candidates->forLine($line, $excluding), $filters),
            ),
            'already_allocated_quantity' => $line->quantity - $this->candidates->remainingQuantity($line, $excluding),
            'remaining_quantity' => $this->candidates->remainingQuantity($line, $excluding),
            'sourcing' => $this->sourcingSummary($line),
        ]);
    }

    /**
     * Staff's search, Supplier/Warehouse filter, availability filter and cost
     * sort, applied after the line's sourcing rules have decided what may be
     * offered at all.
     *
     * @param  list<AllocationCandidate>  $candidates
     * @param  array<string, mixed>  $filters
     * @return list<AllocationCandidate>
     */
    protected function filterCandidates(array $candidates, array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $type = filled($filters['source_type'] ?? null) ? AllocationSourceType::from($filters['source_type']) : null;

        $kept = array_values(array_filter($candidates, function (AllocationCandidate $candidate) use ($search, $type, $filters) {
            if ($type !== null && $candidate->sourceType !== $type) {
                return false;
            }

            if (($filters['availability'] ?? null) === 'available' && ! $candidate->isEligible) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            return str_contains(mb_strtolower(implode(' ', array_filter([
                $candidate->sourceLabel, $candidate->supplierName, $candidate->sourceProductName, $candidate->sourceProductSku,
            ]))), $search);
        }));

        $direction = $filters['sort'] ?? null;

        if ($direction !== null) {
            usort($kept, fn (AllocationCandidate $a, AllocationCandidate $b) => $direction === 'cost_asc'
                ? $a->unitCost->toDecimal() <=> $b->unitCost->toDecimal()
                : $b->unitCost->toDecimal() <=> $a->unitCost->toDecimal());
        }

        return $kept;
    }

    /**
     * What this line froze about its fulfilment: the sourcing group and the
     * canonical product/variation it requires, or an unmatched flag for an
     * order placed before groups or without an explicit mapping.
     *
     * @return array<string, mixed>
     */
    protected function sourcingSummary(OrderItem $line): array
    {
        if ($line->sourcing_group_id === null) {
            return ['state' => 'unmatched', 'group' => null, 'canonical_product' => null, 'canonical_variant' => null];
        }

        $group = ProductSourcingGroup::query()->find($line->sourcing_group_id);
        $product = Product::query()->find($line->sourcing_canonical_product_id);
        $variant = $line->sourcing_canonical_variant_id === null
            ? null
            : ProductVariant::query()->with('values')->find($line->sourcing_canonical_variant_id);

        return [
            'state' => 'matched',
            'group' => $group === null ? null : [
                'id' => $group->public_id, 'code' => $group->code, 'name_en' => $group->name_en, 'name_bn' => $group->name_bn,
                'is_active' => $group->is_active,
            ],
            'canonical_product' => $product === null ? null : ['name' => $product->name, 'sku' => $product->sku],
            'canonical_variant' => $variant?->label(),
        ];
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
            'override' => ['sometimes', 'boolean'],
            // Split allocation (Advanced Order Management batch, Commit 3):
            // omitted, this allocates everything not yet covered by another
            // active allocation, exactly as before split allocation existed.
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'replacing_allocation_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $sourceType = AllocationSourceType::from($validated['source_type']);

        Gate::forUser($actor)->authorize('allocateSource', [$record, $sourceType]);

        $override = (bool) ($validated['override'] ?? false);

        if ($override) {
            Gate::forUser($actor)->authorize('overrideFulfilmentState', $record);
        }

        try {
            $this->allocate->handle(
                $line,
                $sourceType,
                $validated['source_id'],
                $actor,
                $validated['reason'],
                $override,
                isset($validated['quantity']) ? (int) $validated['quantity'] : null,
                $validated['replacing_allocation_id'] ?? null,
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
     * Advance, fail or cancel the Supplier fulfilment commitment behind an
     * on_demand/pre_order allocation (Supplier Bulk Product Listing batch,
     * correction 7/11) -- gated by the same boundary as {@see allocate()}
     * for a Supplier Offer, never `supplier_listing.approve`.
     */
    public function advanceFulfilmentCommitment(Request $request, string $order, string $item, string $commitment): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('manageFulfilmentCommitment', $record);

        $line = $this->line($record, $item);

        /** @var SupplierFulfilmentCommitment $commitmentModel */
        $commitmentModel = SupplierFulfilmentCommitment::query()
            ->whereHas('allocation', fn ($query) => $query->where('order_item_id', $line->id))
            ->where('public_id', $commitment)
            ->firstOrFail();

        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'start_preparing', 'mark_ready', 'fail', 'cancel'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:action,fail,cancel'],
        ]);

        try {
            match ($validated['action']) {
                'confirm' => $this->advanceCommitment->confirm($commitmentModel, $actor),
                'start_preparing' => $this->advanceCommitment->startPreparing($commitmentModel, $actor),
                'mark_ready' => $this->advanceCommitment->markReady($commitmentModel, $actor),
                'fail' => $this->advanceCommitment->fail($commitmentModel, $actor, $validated['reason']),
                'cancel' => $this->advanceCommitment->cancel($commitmentModel, $actor, $validated['reason']),
                default => throw new InvalidArgumentException("Unknown fulfilment commitment action [{$validated['action']}]."),
            };
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.commitment_updated')]);

        return back();
    }

    /**
     * Move an order's fulfilment status by hand (§18, §20).
     */
    public function advanceFulfilmentStatus(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderFulfillmentStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->advanceFulfilment->handle(
                $record,
                OrderFulfillmentStatus::from($validated['status']),
                $actor,
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.lifecycle_updated')]);

        return back();
    }

    /**
     * Move an order's delivery status by hand (§18, §21).
     */
    public function advanceDeliveryStatus(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderDeliveryStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->advanceDelivery->handle(
                $record,
                OrderDeliveryStatus::from($validated['status']),
                $actor,
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.lifecycle_updated')]);

        return back();
    }

    /**
     * Move an order's courier status by hand (§18, §21).
     */
    public function advanceCourierStatus(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderCourierStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->advanceCourier->handle(
                $record,
                OrderCourierStatus::from($validated['status']),
                $actor,
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.lifecycle_updated')]);

        return back();
    }

    /**
     * The full eligible catalogue for one line — every approved, active
     * Supplier offer and every active warehouse stock item, regardless of
     * which product they are catalogued under — paginated and searchable by
     * product, SKU, variant, Supplier or warehouse. Gated the same as
     * {@see allocationCandidates()}: this exposes the same Supplier
     * identity/rate and warehouse cost/margin figures, just across the
     * whole catalogue instead of one product.
     */
    public function searchAllocationSources(Request $request, string $order, string $item): JsonResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('viewAllocationSources', $record);

        $line = $this->line($record, $item);

        $validated = $request->validate([
            'query' => ['sometimes', 'string', 'max:150'],
            'source_type' => ['sometimes', Rule::enum(AllocationSourceType::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $type = isset($validated['source_type']) ? AllocationSourceType::from($validated['source_type']) : null;
        $page = (int) ($validated['page'] ?? 1);

        // A line with a frozen sourcing group only ever sees its group's
        // compatible sources -- the catalogue-wide search would show unrelated
        // products, so it is answered from the same group-scoped list.
        if ($this->candidates->isGrouped($line)) {
            $scoped = $this->filterCandidates($this->candidates->forLine($line), [
                'search' => $validated['query'] ?? '',
                'source_type' => $validated['source_type'] ?? null,
            ]);

            return response()->json([
                'candidates' => array_map(fn (AllocationCandidate $candidate) => $candidate->toArray(), $scoped),
                'page' => 1,
                'per_page' => count($scoped),
                'total' => count($scoped),
                'has_more' => false,
            ]);
        }

        $results = $this->search->search($line, $type, (string) ($validated['query'] ?? ''), $page);

        return response()->json([
            'candidates' => array_map(
                fn (AllocationCandidate $candidate) => $candidate->toArray(),
                $results->items(),
            ),
            'page' => $results->currentPage(),
            'per_page' => $results->perPage(),
            'total' => $results->total(),
            'has_more' => $results->hasMorePages(),
        ]);
    }

    /**
     * Confirm that a Supplier offer or warehouse stock item catalogued under
     * a different product fulfils this line's own product — a durable,
     * audited {@see ProductSourceLink}, reused (not
     * duplicated) by every future order for the same product. Never
     * allocates by itself; a materially different product is refused by the
     * Action, and staff choosing not to confirm simply never allocates from
     * that source.
     */
    public function confirmSourceLink(Request $request, string $order, string $item): JsonResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);
        $line = $this->line($record, $item);

        $validated = $request->validate([
            'source_type' => ['required', Rule::enum(AllocationSourceType::class)],
            'source_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $sourceType = AllocationSourceType::from($validated['source_type']);

        Gate::forUser($actor)->authorize('confirmSourceLink', [$record, $sourceType]);

        try {
            $link = $this->confirmLink->handle(
                $line->product,
                $line->product_variant_id === null ? null : $line->variant,
                $sourceType,
                $validated['source_id'],
                $actor,
                $validated['reason'],
            );
        } catch (AllocationRefused $refused) {
            throw ValidationException::withMessages(['source_id' => $refused->getMessage()]);
        }

        return response()->json(['link' => ['id' => $link->public_id]]);
    }

    /**
     * One active allocation holding part (or all) of a line, redacted field
     * by field for a viewer who lacks the permission each figure answers to.
     * A split line (Advanced Order Management batch, Commit 3) calls this
     * once per active allocation, not once per line.
     *
     * @return array<string, mixed>
     */
    protected function allocationSummary(
        OrderItemAllocation $allocation,
        bool $canViewSupplierPricing,
        bool $canViewCatalogPricing,
        bool $canManageFulfilmentCommitment,
    ): array {
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
            'quantity' => $allocation->quantity,
            'unit_cost' => $canViewFinancials ? $allocation->unit_cost->jsonSerialize() : null,
            'expected_margin' => $canViewFinancials ? $allocation->expected_margin->jsonSerialize() : null,
            'allocated_at' => $allocation->allocated_at->toIso8601String(),
            // Only a Supplier Offer allocation can carry one (Supplier Bulk
            // Product Listing batch, correction 7) -- shown behind the same
            // supplier_pricing.view gate as the rest of this Supplier's
            // figures, since its presence alone says this line has no
            // physical stock behind it.
            'fulfilment_commitment' => $isSupplierSourced && $canViewSupplierPricing
                ? $this->fulfilmentCommitmentSummary($allocation, $canManageFulfilmentCommitment)
                : null,
            // Only a Supplier-sourced allocation ever owes anything (§8) --
            // behind the same supplier_pricing.view gate as its rate/margin,
            // since the payable amount is derived from that same rate.
            'payable' => $isSupplierSourced && $canViewSupplierPricing
                ? $this->payableSummary($allocation)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function payableSummary(OrderItemAllocation $allocation): ?array
    {
        $payable = $allocation->payable;

        if ($payable === null) {
            return null;
        }

        return [
            'id' => $payable->public_id,
            'status' => $payable->status->value,
            'status_label' => $payable->status->label(),
            'status_tone' => $payable->status->tone(),
            'gross_amount' => $payable->gross_amount->jsonSerialize(),
            'reversed_amount' => $payable->reversedAmount()->jsonSerialize(),
            'net_amount' => $payable->netAmount()->jsonSerialize(),
            'delivered_at' => $payable->delivered_at?->toIso8601String(),
            'payment_settled_at' => $payable->payment_settled_at?->toIso8601String(),
            'eligible_at' => $payable->eligible_at?->toIso8601String(),
            'settled_at' => $payable->settled_at?->toIso8601String(),
            'cancelled_at' => $payable->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fulfilmentCommitmentSummary(OrderItemAllocation $allocation, bool $canManage): ?array
    {
        $commitment = $allocation->fulfilmentCommitment;
        $offer = $allocation->offer;

        if ($commitment === null || $offer === null) {
            return null;
        }

        // Every transition this batch ships is staff-initiated -- see
        // AdvanceSupplierFulfilmentCommitment. A Supplier confirming their
        // own commitment is Advanced Order Management's own dependency, not
        // built here; the UI must say so rather than imply self-service
        // exists.
        $actionsByTransition = [
            FulfilmentCommitmentStatus::Confirmed->value => 'confirm',
            FulfilmentCommitmentStatus::Preparing->value => 'start_preparing',
            FulfilmentCommitmentStatus::Ready->value => 'mark_ready',
            FulfilmentCommitmentStatus::Failed->value => 'fail',
            FulfilmentCommitmentStatus::Cancelled->value => 'cancel',
        ];

        return [
            'id' => $commitment->public_id,
            'status' => $commitment->status->value,
            'status_label' => $commitment->status->label(),
            'status_tone' => $commitment->status->tone(),
            'is_terminal' => $commitment->status->isTerminal(),
            'is_staff_managed' => true,
            'supply_mode' => $offer->supply_mode->value,
            'supply_mode_label' => $offer->supply_mode->label(),
            'lead_time_days' => $offer->lead_time_days,
            'fulfilment_capacity' => $offer->fulfilment_capacity,
            'quantity' => $commitment->quantity,
            'due_at' => $commitment->due_at?->toIso8601String(),
            'confirmed_at' => $commitment->confirmed_at?->toIso8601String(),
            'failed_reason' => $commitment->failed_reason,
            'failed_at' => $commitment->failed_at?->toIso8601String(),
            'cancelled_reason' => $commitment->cancelled_reason,
            'cancelled_at' => $commitment->cancelled_at?->toIso8601String(),
            'can_manage' => $canManage,
            'available_actions' => $canManage
                ? array_values(array_filter(
                    array_map(
                        fn (FulfilmentCommitmentStatus $to) => $actionsByTransition[$to->value] ?? null,
                        $commitment->status->transitionsTo(),
                    ),
                ))
                : [],
            'history' => $commitment->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                // Null for a Supplier's own action -- there is no `users`
                // row to name (D25) -- so the source stands in: "Supplier"
                // reads better than a blank attribution.
                'changed_by' => $this->historyActorLabel($change->changedBy, $change->source),
                'changed_at' => $change->changed_at->toIso8601String(),
                'reason' => $change->reason,
            ])->all(),
        ];
    }

    /**
     * Who to show for a fulfilment-commitment history row — the staff
     * member's name when one acted, else the source itself ("Supplier",
     * "System"): a Supplier's own action has no `users` row to name at all
     * (D25), and a blank attribution reads worse than naming the source.
     */
    protected function historyActorLabel(?User $changedBy, SupplierStatusChangeSource $source): string
    {
        if ($changedBy === null) {
            return $source->label();
        }

        return $changedBy->name;
    }

    /**
     * One lifecycle axis (fulfilment, delivery or courier), with the actions
     * a manager may legally take from here derived straight from
     * {@see TransitionableState::transitionsTo()} —
     * never a hand-maintained list, so no transition route can exist that
     * this never offers a button for.
     *
     * @param  Collection<int, OrderFulfillmentStatusChange>|Collection<int, OrderDeliveryStatusChange>|Collection<int, OrderCourierStatusChange>  $history
     * @return array<string, mixed>
     */
    protected function lifecycleSummary(
        OrderFulfillmentStatus|OrderDeliveryStatus|OrderCourierStatus $status,
        Collection $history,
        bool $canManage,
    ): array {
        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'is_terminal' => $status->isTerminal(),
            'can_manage' => $canManage,
            'available_actions' => $canManage
                ? array_map(fn (OrderFulfillmentStatus|OrderDeliveryStatus|OrderCourierStatus $to) => $to->value, $status->transitionsTo())
                : [],
            'history' => $history->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'changed_by' => $change->changedBy?->name,
                'changed_at' => $change->changed_at->toIso8601String(),
                'reason' => $change->reason,
            ])->all(),
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
