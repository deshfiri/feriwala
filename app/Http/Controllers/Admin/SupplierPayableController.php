<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff visibility into every Supplier payable (D25, P13-22).
 *
 * Gated by `supplier_payable.view`, deliberately separate from
 * `supplier_payable.approve` (settlement, P13-23) — a role can see what is
 * owed to Suppliers without holding the right to move money for it.
 */
class SupplierPayableController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierPayable::class);

        $payables = SupplierPayable::query()
            ->with(['supplier:id,public_id,business_name', 'order:id,reference'])
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->when($request->filled('order'), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('reference', 'ilike', '%'.$request->string('order').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierPayable $payable) => $this->row($payable));

        return Inertia::render('admin/supplier-payables/index', [
            'payables' => $payables,
            'statuses' => array_map(
                fn (PayableStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                PayableStatus::cases(),
            ),
        ]);
    }

    public function show(SupplierPayable $payable): Response
    {
        Gate::authorize('view', $payable);

        $payable->load(['supplier:id,public_id,business_name', 'order:id,reference', 'orderItem:id,product_name,variant_label', 'reversals', 'statusHistory.changedBy']);

        return Inertia::render('admin/supplier-payables/show', [
            'payable' => [
                ...$this->row($payable),
                'product_name' => $payable->orderItem->product_name,
                'variant_label' => $payable->orderItem->variant_label,
                'reversals' => $payable->reversals->map(fn ($reversal) => [
                    'quantity' => $reversal->quantity,
                    'amount' => $reversal->amount_minor->jsonSerialize(),
                    'reason' => $reversal->reason,
                    'created_at' => $reversal->created_at->toIso8601String(),
                ])->all(),
                'history' => $payable->statusHistory->map(fn ($change) => [
                    'previous_status' => $change->previous_status?->label(),
                    'new_status' => $change->new_status->label(),
                    'reason' => $change->reason,
                    'changed_by' => $change->changedBy?->name,
                    'changed_at' => $change->changed_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierPayable $payable): array
    {
        return [
            'id' => $payable->public_id,
            'reference' => $payable->reference,
            'supplier' => $payable->supplier->business_name,
            'order_reference' => $payable->order->reference,
            'quantity' => $payable->quantity,
            'supplier_rate' => $payable->supplier_rate_minor->jsonSerialize(),
            'gross_amount' => $payable->gross_amount_minor->jsonSerialize(),
            'net_amount' => $payable->netAmount()->jsonSerialize(),
            'status' => $payable->status->value,
            'status_label' => $payable->status->label(),
            'status_tone' => $payable->status->tone(),
            'delivered_at' => $payable->delivered_at?->toIso8601String(),
            'payment_settled_at' => $payable->payment_settled_at?->toIso8601String(),
            'eligible_at' => $payable->eligible_at?->toIso8601String(),
            'created_at' => $payable->created_at->toIso8601String(),
        ];
    }
}
