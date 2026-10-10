<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff visibility into every Supplier-backed order line (D25, P13-21).
 *
 * Gated by `supplier_pricing.view` — the same permission that already guards
 * a Supplier's rate — because every row here carries the Supplier Rate, the
 * Platform Rate and the margin between them. Filterable by Supplier, order,
 * status and date.
 */
class SupplierAllocationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierOffer::class);

        $items = OrderItem::query()
            ->whereNotNull('supplier_id')
            ->with(['order:id,reference,status,placed_at', 'supplier:id,public_id,business_name', 'product:id,name', 'variant:id,public_id'])
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->when($request->filled('order'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('reference', 'ilike', '%'.$request->string('order').'%')))
            ->when($request->filled('status'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('status', $request->string('status')->toString())))
            ->when($request->filled('date_from'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('placed_at', '>=', $request->date('date_from'))))
            ->when($request->filled('date_to'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('placed_at', '<=', $request->date('date_to'))))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            // Every row here already matched `whereNotNull('supplier_id')`, so
            // every Supplier-only column is present — the null-coalescing
            // below satisfies static analysis without hiding a real gap.
            ->through(fn (OrderItem $item) => [
                'id' => $item->public_id,
                'order_reference' => $item->order->reference,
                'order_status' => $item->order->status->value,
                'placed_at' => $item->order->placed_at->toIso8601String(),
                'supplier' => $item->supplier?->business_name,
                'product_name' => $item->product_name,
                'variant' => $item->variant?->public_id,
                'quantity' => $item->supplier_allocated_quantity,
                'supplier_rate' => $item->supplier_rate?->jsonSerialize(),
                'platform_rate' => $item->platform_rate?->jsonSerialize(),
                'platform_margin' => $item->platform_margin?->jsonSerialize(),
            ]);

        return Inertia::render('admin/supplier-allocations/index', ['allocations' => $items]);
    }

    public function show(string $item): Response
    {
        Gate::authorize('viewAny', SupplierOffer::class);

        /** @var OrderItem $model */
        $model = OrderItem::query()
            ->whereNotNull('supplier_id')
            ->where('public_id', $item)
            ->with(['order:id,reference,status,placed_at', 'supplier:id,public_id,business_name', 'product:id,name', 'variant:id,public_id', 'supplierOffer:id,public_id,reference', 'supplierPayable'])
            ->firstOrFail();

        return Inertia::render('admin/supplier-allocations/show', [
            'allocation' => [
                'id' => $model->public_id,
                'order_reference' => $model->order->reference,
                'order_status' => $model->order->status->value,
                'placed_at' => $model->order->placed_at->toIso8601String(),
                'supplier' => $model->supplier?->business_name,
                'offer_reference' => $model->supplierOffer?->reference,
                'product_name' => $model->product_name,
                'variant' => $model->variant?->public_id,
                'quantity' => $model->supplier_allocated_quantity,
                'supplier_rate' => $model->supplier_rate?->jsonSerialize(),
                'platform_rate' => $model->platform_rate?->jsonSerialize(),
                'platform_margin' => $model->platform_margin?->jsonSerialize(),
                'allocated_at' => $model->supplier_allocated_at?->toIso8601String(),
                'payable' => $model->supplierPayable === null ? null : [
                    'id' => $model->supplierPayable->public_id,
                    'reference' => $model->supplierPayable->reference,
                    'status' => $model->supplierPayable->status->value,
                    'status_label' => $model->supplierPayable->status->label(),
                    'status_tone' => $model->supplierPayable->status->tone(),
                ],
            ],
        ]);
    }
}
