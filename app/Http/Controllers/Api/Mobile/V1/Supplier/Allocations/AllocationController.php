<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Allocations;

use App\Domain\Order\Models\OrderItem;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Resources\Mobile\Supplier\Allocations\SupplierAllocationDetailResource;
use App\Http\Resources\Mobile\Supplier\Allocations\SupplierAllocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A Supplier's own allocated order lines (D25, P13-21). Read-only.
 *
 * Scoped by `supplier_id` in the query, so another Supplier's order line is a
 * `404`. Closed to a supplier that is not operational
 * (`403 supplier_not_operational`).
 */
class AllocationController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Allocations
     *
     * The order lines allocated to the supplier, newest first, 20 to a page,
     * each with its own rate and its payable. Never the platform rate or margin.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $supplier = $this->supplierFor($request);

        return SupplierAllocationResource::collection(
            OrderItem::query()
                ->where('supplier_id', $supplier->id)
                ->with(['order:id,reference,status,placed_at', 'product:id,name', 'variant:id,public_id', 'supplierPayable'])
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    /**
     * Allocation detail
     *
     * One allocated order line with the reversals on its payable. `404` for a
     * line that is not this supplier's.
     */
    public function show(Request $request, string $item): SupplierAllocationDetailResource
    {
        $supplier = $this->supplierFor($request);

        $model = OrderItem::query()
            ->where('supplier_id', $supplier->id)
            ->where('public_id', $item)
            ->with(['order:id,reference,status,placed_at', 'product:id,name', 'variant:id,public_id', 'supplierPayable.reversals'])
            ->firstOrFail();

        return new SupplierAllocationDetailResource($model);
    }
}
