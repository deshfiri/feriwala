<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own allocated order lines (D25, P13-21).
 *
 * Scoped through the authenticated Supplier's own `offers()` relation — one
 * Supplier can never see another's allocation, another's rate, or an order
 * line that is not its own. Only what §-equivalent D25 lets a Supplier see:
 * the product and variation, the quantity, its own Supplier Rate, and the
 * line's pending or eligible payable — never the Client's identity beyond
 * what fulfilment needs, never the Platform Rate or margin.
 */
class AllocationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $items = OrderItem::query()
            ->where('supplier_id', $supplier->id)
            ->with(['order:id,reference,status,placed_at', 'product:id,name', 'variant:id,public_id', 'supplierPayable'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (OrderItem $item) => $this->row($item));

        return Inertia::render('supplier/allocations/index', ['allocations' => $items]);
    }

    public function show(Request $request, string $item): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        /** @var OrderItem $model */
        $model = OrderItem::query()
            ->where('supplier_id', $supplier->id)
            ->where('public_id', $item)
            ->with(['order:id,reference,status,placed_at', 'product:id,name', 'variant:id,public_id', 'supplierPayable.reversals'])
            ->firstOrFail();

        return Inertia::render('supplier/allocations/show', ['allocation' => $this->row($model, detailed: true)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(OrderItem $item, bool $detailed = false): array
    {
        $payable = $item->supplierPayable;

        return [
            'id' => $item->public_id,
            'order_reference' => $item->order->reference,
            'order_status' => $item->order->status->value,
            'placed_at' => $item->order->placed_at->toIso8601String(),
            'product_name' => $item->product->name,
            'variant' => $item->variant?->public_id,
            'quantity' => $item->supplier_allocated_quantity,
            'supplier_rate' => $item->supplier_rate_minor?->jsonSerialize(),
            'allocated_at' => $item->supplier_allocated_at?->toIso8601String(),
            'payable' => $payable === null ? null : [
                'id' => $payable->public_id,
                'reference' => $payable->reference,
                'status' => $payable->status->value,
                'status_label' => $payable->status->label(),
                'status_tone' => $payable->status->tone(),
                'gross_amount' => $payable->gross_amount_minor->jsonSerialize(),
                'net_amount' => $payable->netAmount()->jsonSerialize(),
                'delivered_at' => $payable->delivered_at?->toIso8601String(),
                'payment_settled_at' => $payable->payment_settled_at?->toIso8601String(),
                'eligible_at' => $payable->eligible_at?->toIso8601String(),
                'reversals' => $detailed ? $payable->reversals->map(fn ($reversal) => [
                    'quantity' => $reversal->quantity,
                    'amount' => $reversal->amount_minor->jsonSerialize(),
                    'reason' => $reversal->reason,
                    'created_at' => $reversal->created_at->toIso8601String(),
                ])->all() : null,
            ],
        ];
    }
}
