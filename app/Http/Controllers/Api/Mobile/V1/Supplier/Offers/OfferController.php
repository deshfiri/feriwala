<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Offers;

use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Resources\Mobile\Supplier\Offers\SupplierOfferDetailResource;
use App\Http\Resources\Mobile\Supplier\Offers\SupplierOfferResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * A Supplier's own products, "My Products" (D25, P13-13). Read-only.
 *
 * Every offer is reached through the signed-in Supplier's own `offers()`
 * relation, so another Supplier's offer is a `404`. The platform's rates are
 * never shown: the Supplier sees only its own base price.
 */
class OfferController extends MobileController
{
    use ResolvesSupplier;

    public function __construct(protected ProductMediaStore $media) {}

    /**
     * Offers
     *
     * Every offer the supplier holds, most recently updated first, 15 to a page,
     * with its own rate, supply mode and available quantity.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $offers = $this->supplierFor($request)->offers()
            ->with(['product:id,public_id,name,slug,sku', 'variant.values', 'stock'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $this->attachImages($offers->getCollection());

        return SupplierOfferResource::collection($offers);
    }

    /**
     * Offer detail
     *
     * One offer with the history of the supplier's own rate. `404` for another
     * supplier's offer.
     */
    public function show(Request $request, string $offer): SupplierOfferDetailResource
    {
        $model = $this->supplierFor($request)->offers()
            ->with(['product', 'variant.values', 'stock', 'priceHistory'])
            ->where('public_id', $offer)
            ->firstOrFail();

        $this->attachImages(collect([$model]));

        return new SupplierOfferDetailResource($model);
    }

    /**
     * Sets each offer's `cover_image` (its product's first image) from one query.
     *
     * @param  Collection<int, SupplierOffer>  $offers
     */
    protected function attachImages(Collection $offers): void
    {
        $images = ProductMedia::query()
            ->whereIn('product_id', $offers->pluck('product_id')->unique()->all())
            ->where('type', ProductMedia::TYPE_IMAGE)
            ->where('position', 1)
            ->get()
            ->mapWithKeys(fn (ProductMedia $item) => [$item->product_id => [
                'url' => $this->media->url($item->path),
                'alt' => $item->alt_text,
            ]]);

        foreach ($offers as $offer) {
            $offer->setAttribute('cover_image', $images[$offer->product_id] ?? null);
        }
    }
}
