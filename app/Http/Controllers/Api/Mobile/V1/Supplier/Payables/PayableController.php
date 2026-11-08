<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Payables;

use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Payables\PayableListRequest;
use App\Http\Resources\Mobile\Supplier\Payables\SupplierPayableDetailResource;
use App\Http\Resources\Mobile\Supplier\Payables\SupplierPayableResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What Feriwala owes the Supplier, per allocated order line (D25, P13-22).
 * Read-only.
 *
 * Scoped by `supplier_id` in the query, so another Supplier's payable is a
 * `404`.
 */
class PayableController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Payables
     *
     * The supplier's payables, newest first, 20 to a page, optionally filtered by
     * `status`. `statuses` lists the values the filter accepts.
     *
     * @response array{data: list<SupplierPayableResource>, links: array<string, string|null>, meta: array<string, mixed>, statuses: list<array{value: string, label: string}>}
     */
    public function index(PayableListRequest $request): AnonymousResourceCollection
    {
        $supplier = $this->supplierFor($request);

        $payables = SupplierPayable::query()
            ->where('supplier_id', $supplier->id)
            ->with(['order:id,reference', 'orderItem:id,product_name,variant_label'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return SupplierPayableResource::collection($payables)->additional([
            'statuses' => array_map(
                fn (PayableStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                PayableStatus::cases(),
            ),
        ]);
    }

    /**
     * Payable detail
     *
     * One payable with its reversals and status history. `404` for another
     * supplier's payable.
     */
    public function show(Request $request, string $payable): SupplierPayableDetailResource
    {
        $model = SupplierPayable::query()
            ->where('supplier_id', $this->supplierFor($request)->id)
            ->where('public_id', $payable)
            ->with(['order:id,reference', 'orderItem:id,product_name,variant_label', 'reversals', 'statusHistory'])
            ->firstOrFail();

        return new SupplierPayableDetailResource($model);
    }
}
