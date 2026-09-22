<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own payables (D25, P13-22).
 *
 * Scoped through the authenticated Supplier's own `supplier_id` — one Supplier
 * can never see another's payable or its rate. Shows the pending and eligible
 * amounts, and what has been reversed; never the platform margin, which is
 * Feriwala's own figure.
 */
class PayableController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $payables = SupplierPayable::query()
            ->where('supplier_id', $supplier->id)
            ->with(['order:id,reference', 'orderItem:id,product_name,variant_label'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierPayable $payable) => $this->row($payable));

        return Inertia::render('supplier/payables/index', ['payables' => $payables]);
    }

    public function show(Request $request, string $payable): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        /** @var SupplierPayable $model */
        $model = SupplierPayable::query()
            ->where('supplier_id', $supplier->id)
            ->where('public_id', $payable)
            ->with(['order:id,reference', 'orderItem:id,product_name,variant_label', 'reversals', 'statusHistory'])
            ->firstOrFail();

        return Inertia::render('supplier/payables/show', [
            'payable' => [
                ...$this->row($model),
                'reversals' => $model->reversals->map(fn ($reversal) => [
                    'quantity' => $reversal->quantity,
                    'amount' => $reversal->amount_minor->jsonSerialize(),
                    'reason' => $reversal->reason,
                    'created_at' => $reversal->created_at->toIso8601String(),
                ])->all(),
                'history' => $model->statusHistory->map(fn ($change) => [
                    'previous_status' => $change->previous_status?->label(),
                    'new_status' => $change->new_status->label(),
                    'reason' => $change->reason,
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
            'order_reference' => $payable->order->reference,
            'product_name' => $payable->orderItem->product_name,
            'variant_label' => $payable->orderItem->variant_label,
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
            'settled_at' => $payable->settled_at?->toIso8601String(),
            'created_at' => $payable->created_at->toIso8601String(),
        ];
    }
}
