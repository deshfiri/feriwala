<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Listings;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Supplier\Actions\ArchiveSupplierListingDraft;
use App\Domain\Supplier\Actions\SaveSupplierListingDraft;
use App\Domain\Supplier\Actions\SubmitSupplierListing;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Listings\ListingListRequest;
use App\Http\Requests\Mobile\Supplier\Listings\SaveListingRequest;
use App\Http\Resources\Mobile\Supplier\Listings\ListingDetailResource;
use App\Http\Resources\Mobile\Supplier\Listings\ListingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier's own Product Listing Requests (D25, P13-9, P13-11).
 *
 * Every lookup goes through the signed-in Supplier's own `listings()` relation,
 * so another Supplier's request is a `404`, never a refusal. Submitting one
 * never writes to `products`: it is a proposal until staff decides it. Closed to
 * a Supplier that is not approved (`403 supplier_not_operational`).
 */
class ListingController extends MobileController
{
    use ResolvesSupplier;

    /**
     * List listing requests
     *
     * The supplier's own listing requests, most recently updated first, 15 to a
     * page, optionally filtered by `status`. `statuses` lists the accepted values.
     *
     * @response array{data: list<ListingResource>, links: array<string, string|null>, meta: array<string, mixed>, statuses: list<array{value: string, label: string}>}
     */
    public function index(ListingListRequest $request): AnonymousResourceCollection
    {
        $listings = $this->supplierFor($request)->listings()
            ->with('lot')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return ListingResource::collection($listings)->additional([
            'statuses' => array_map(
                fn (ListingStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                ListingStatus::cases(),
            ),
        ]);
    }

    /**
     * Listing form options
     *
     * The active categories and brands, and the catalogue attributes with their
     * values, to choose from. A supplier only ever selects what is already in the
     * catalogue.
     *
     * @response array{data: array{categories: list<array{value: string, label: string}>, brands: list<array{value: string, label: string}>, attributes: list<array{id: string, name: string, values: list<array{value: string, label: string}>}>}}
     */
    public function options(): JsonResponse
    {
        return new JsonResponse(['data' => [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Category $category) => ['value' => $category->public_id, 'label' => $category->name])->all(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Brand $brand) => ['value' => $brand->public_id, 'label' => $brand->name])->all(),
            'attributes' => ProductAttribute::query()->orderBy('sort_order')->with('values')->get()
                ->map(fn (ProductAttribute $attribute) => [
                    'id' => $attribute->public_id,
                    'name' => $attribute->name,
                    'values' => $attribute->values->map(fn (ProductAttributeValue $value) => [
                        'value' => $value->public_id,
                        'label' => $value->value,
                    ])->all(),
                ])->all(),
        ]]);
    }

    /**
     * Save a listing draft
     *
     * Creates a draft with its proposed variations. Rates are decimal Taka
     * strings. `422` on any invalid field.
     */
    public function store(SaveListingRequest $request, SaveSupplierListingDraft $save): JsonResponse
    {
        [$attributes, $items] = $request->draft();

        try {
            $listing = $save->handle($this->supplierFor($request), $attributes, $items);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return (new ListingDetailResource($this->detailed($listing)))->response()->setStatusCode(201);
    }

    /**
     * Show a listing
     *
     * The request in full, with its variations, images, the reviewer's decision
     * and its status history. `404` for any other supplier's request.
     */
    public function show(Request $request, string $listing): ListingDetailResource
    {
        return new ListingDetailResource($this->detailed($this->ownListing($request, $listing)));
    }

    /**
     * Update a listing
     *
     * Rewrites a draft, or one sent back for correction. `422` when it can no
     * longer be edited. `404` for any other supplier's request.
     */
    public function update(SaveListingRequest $request, string $listing, SaveSupplierListingDraft $save): ListingDetailResource
    {
        $model = $this->ownListing($request, $listing);

        [$attributes, $items] = $request->draft();

        try {
            $save->handle($this->supplierFor($request), $attributes, $items, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return new ListingDetailResource($this->detailed($model));
    }

    /**
     * Submit a listing for review
     *
     * `422` when it is not ready, or when it belongs to a batch (a batch is
     * submitted whole). `404` for any other supplier's request.
     */
    public function submit(Request $request, string $listing, SubmitSupplierListing $submit): ListingDetailResource
    {
        $model = $this->ownListing($request, $listing);

        if ($model->lot_id !== null) {
            throw ValidationException::withMessages(['listing' => __('supplier.listing_lots.submit_whole_batch')]);
        }

        try {
            $submit->handle($this->supplierFor($request), $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return new ListingDetailResource($this->detailed($model));
    }

    /**
     * Archive a draft listing
     *
     * Withdraws a draft. `422` when it is no longer a draft. `404` for any other
     * supplier's request.
     */
    public function archive(Request $request, string $listing, ArchiveSupplierListingDraft $archive): ListingDetailResource
    {
        $model = $this->ownListing($request, $listing);

        try {
            $archive->handle($this->supplierFor($request), $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return new ListingDetailResource($this->detailed($model));
    }

    protected function ownListing(Request $request, string $publicId): SupplierProductListing
    {
        return $this->supplierFor($request)->listings()->where('public_id', $publicId)->firstOrFail();
    }

    protected function detailed(SupplierProductListing $listing): SupplierProductListing
    {
        return $listing->refresh()->load([
            'lot',
            'category',
            'brand',
            'media',
            'items.attributeValues',
            'items.variantMedia.item',
            'statusHistory' => fn ($query) => $query->orderByDesc('id'),
        ]);
    }
}
