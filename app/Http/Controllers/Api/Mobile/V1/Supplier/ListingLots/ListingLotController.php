<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\ListingLots;

use App\Domain\Supplier\Actions\ArchiveSupplierListingLotDraft;
use App\Domain\Supplier\Actions\CreateSupplierListingLot;
use App\Domain\Supplier\Actions\SubmitSupplierListingLot;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\ListingLots\CreateListingLotRequest;
use App\Http\Requests\Mobile\Supplier\ListingLots\ListingLotListRequest;
use App\Http\Resources\Mobile\Supplier\ListingLots\ListingLotDetailResource;
use App\Http\Resources\Mobile\Supplier\ListingLots\ListingLotResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier's own multi-product listing batches: drafted, then submitted once.
 *
 * Every lookup goes through the signed-in Supplier's own `lots()` relation, so
 * another Supplier's batch is a `404`. Product entries are managed through the
 * lot item endpoints and their images through the listing media endpoints.
 * Closed to a Supplier that is not approved (`403 supplier_not_operational`).
 */
class ListingLotController extends MobileController
{
    use ResolvesSupplier;

    /**
     * List batches
     *
     * The supplier's own batches, most recently updated first, 15 to a page,
     * optionally filtered by `status`. `statuses` lists the accepted values.
     *
     * @response array{data: list<ListingLotResource>, links: array<string, string|null>, meta: array<string, mixed>, statuses: list<array{value: string, label: string}>}
     */
    public function index(ListingLotListRequest $request): AnonymousResourceCollection
    {
        $lots = $this->supplierFor($request)->lots()
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return ListingLotResource::collection($lots)->additional([
            'statuses' => array_map(
                fn (LotStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                LotStatus::cases(),
            ),
        ]);
    }

    /**
     * Start a batch
     *
     * Opens an empty draft batch, optionally named with `title`.
     */
    public function store(CreateListingLotRequest $request, CreateSupplierListingLot $create): JsonResponse
    {
        $lot = $create->handle($this->supplierFor($request), $request->validated()['title'] ?? null);

        return (new ListingLotDetailResource($this->detailed($lot)))->response()->setStatusCode(201);
    }

    /**
     * Show a batch
     *
     * The batch with every entry, variation and image, and its status history.
     * `404` for any other supplier's batch.
     */
    public function show(Request $request, string $lot): ListingLotDetailResource
    {
        return new ListingLotDetailResource($this->detailed($this->ownLot($request, $lot)));
    }

    /**
     * Submit a batch
     *
     * Sends the whole batch for review, once. `422` when it is not a draft, is
     * empty or has an incomplete entry. `404` for any other supplier's batch.
     */
    public function submit(Request $request, string $lot, SubmitSupplierListingLot $submit): ListingLotDetailResource
    {
        $model = $this->ownLot($request, $lot);

        try {
            $submit->handle($this->supplierFor($request), $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }

        return new ListingLotDetailResource($this->detailed($model));
    }

    /**
     * Discard a draft batch
     *
     * `422` when the batch is no longer a draft. `404` for any other supplier's
     * batch.
     */
    public function archive(Request $request, string $lot, ArchiveSupplierListingLotDraft $archive): ListingLotDetailResource
    {
        $model = $this->ownLot($request, $lot);

        try {
            $archive->handle($this->supplierFor($request), $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }

        return new ListingLotDetailResource($this->detailed($model));
    }

    protected function ownLot(Request $request, string $publicId): SupplierProductListingLot
    {
        return $this->supplierFor($request)->lots()->where('public_id', $publicId)->firstOrFail();
    }

    protected function detailed(SupplierProductListingLot $lot): SupplierProductListingLot
    {
        return $lot->refresh()->load([
            'items' => fn ($query) => $query->with([
                'category',
                'brand',
                'media',
                'items.attributeValues',
                'items.variantMedia.item',
            ]),
            'statusHistory' => fn ($query) => $query->orderByDesc('id'),
        ]);
    }
}
