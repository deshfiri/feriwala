<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Api\StorefrontProductPayload;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A storefront reading its own catalogue (contract §5.1, P5-22).
 *
 * **Only this website's published selections**, found through the website the
 * credential belongs to. A product another website sells, or one this website
 * selected but never published, is a `404` — never a `403` that would confirm
 * it exists somewhere (contract §4.5).
 *
 * `updated_since` is what makes scheduled reconciliation cheap: a storefront
 * asks only for what changed, whether the partner changed the price or
 * Feriwala changed the product.
 */
class ProductController extends StorefrontController
{
    public function __construct(
        protected StorefrontProductPayload $payload,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $since = null;

        if ($request->filled('updated_since')) {
            try {
                $since = CarbonImmutable::parse($request->string('updated_since')->toString());
            } catch (Throwable) {
                return StorefrontError::respond($request, 422, 'validation_failed', 'updated_since is not a date.', [
                    'field' => 'updated_since',
                ]);
            }
        }

        $page = $this->published($website)
            ->when($since !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('website_products.updated_at', '>=', $since)
                ->orWhereHas('product', fn (Builder $product) => $product->where('updated_at', '>=', $since))))
            ->when($request->filled('category'), fn (Builder $query) => $query
                ->whereHas('websiteCategory', fn (Builder $category) => $category
                    ->where('slug', $request->string('category')->toString())
                    ->orWhere('public_id', $request->string('category')->toString())))
            ->orderBy('website_products.id')
            ->cursorPaginate($this->limit($request));

        return new JsonResponse($this->envelope(
            $page,
            collect($page->items())
                ->map(fn (WebsiteProduct $selection) => $this->payload->for($selection, $website))
                ->all(),
        ));
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $website = $this->website($request);

        /** @var WebsiteProduct|null $selection */
        $selection = $this->published($website)
            ->whereHas('product', fn (Builder $query) => $query
                ->where('public_id', $product)
                ->orWhere('slug', $product))
            ->first();

        if ($selection === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such product on this website.');
        }

        return new JsonResponse($this->payload->for($selection, $website));
    }

    /**
     * This website's published selections, and only its.
     *
     * @return Builder<WebsiteProduct>
     */
    protected function published(Website $website): Builder
    {
        return WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->published()
            ->with([
                'product.brand',
                'product.category',
                'product.media',
                'product.variants.values.attribute',
                'websiteCategory',
            ]);
    }
}
