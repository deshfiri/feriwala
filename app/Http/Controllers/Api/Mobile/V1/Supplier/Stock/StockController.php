<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Stock;

use App\Domain\Supplier\Actions\SubmitSupplierStockUpdate;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Stock\SubmitStockUpdateRequest;
use App\Http\Resources\Mobile\Supplier\Stock\SupplierStockOfferResource;
use App\Http\Resources\Mobile\Supplier\Stock\SupplierStockUpdateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Supplier availability foundation, from the Supplier's own side (D25,
 * P13-15).
 *
 * Only a submission: the quantity moves solely once staff approve it. Every
 * offer is reached through the signed-in Supplier's own `offers()` relation,
 * so another Supplier's offer is a `404`.
 */
class StockController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Stock
     *
     * Every offer with its approved quantity, whether an update is awaiting
     * review, and its five most recent submissions.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SupplierStockOfferResource::collection(
            $this->supplierFor($request)->offers()
                ->with(['product:id,public_id,name', 'variant:id,public_id', 'stock', 'stockUpdates' => fn ($query) => $query->limit(5)])
                ->orderBy('id')
                ->get(),
        );
    }

    /**
     * Submit an availability update
     *
     * Proposes a new `quantity` for one of the supplier's own offers. It is
     * recorded as pending and takes effect only once staff approve it. `404` for
     * an offer that is not this supplier's; `422` on `quantity`.
     *
     * @response 201 array{data: SupplierStockUpdateResource}
     */
    public function store(SubmitStockUpdateRequest $request, string $offer, SubmitSupplierStockUpdate $submit): JsonResponse
    {
        $supplier = $this->supplierFor($request);

        $model = $supplier->offers()->where('public_id', $offer)->firstOrFail();

        try {
            $update = $submit->handle($supplier, $model, $request->validated('quantity'), $request->validated('note'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return (new SupplierStockUpdateResource($update))->response()->setStatusCode(201);
    }
}
