<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites;

use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\SelectProductForWebsite;
use App\Domain\Website\Actions\SetWebsiteProductPublication;
use App\Domain\Website\Actions\UpdateWebsiteProduct;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\WebsiteCatalogue;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Websites\Concerns\ResolvesOwnWebsite;
use App\Http\Requests\Mobile\Websites\PublishWebsiteProductRequest;
use App\Http\Requests\Mobile\Websites\SelectWebsiteProductRequest;
use App\Http\Requests\Mobile\Websites\UpdateWebsiteProductRequest;
use App\Http\Requests\Mobile\Websites\WebsiteProductListRequest;
use App\Http\Resources\Mobile\Websites\WebsiteProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The products one storefront sells (§15, §15.1).
 *
 * **Selection, never authorship.** Every product here already exists in
 * Feriwala's catalogue; this records which of them one shop sells, at what price
 * within the administrator's bounds, in what order, and whether they are on
 * sale. Nothing here creates a product (§12, §16.3). Self-scoped: the selection
 * is found inside the website, which is found inside the account.
 */
class WebsiteProductController extends MobileController
{
    use ResolvesOwnWebsite;

    public function __construct(
        protected WebsiteOverview $overview,
        protected WebsiteCatalogue $catalogue,
        protected Entitlements $entitlements,
        protected ProductEligibility $eligibility,
    ) {}

    /**
     * List website products
     *
     * The products this storefront sells, 20 to a page, ordered by their display order.
     * Each row carries the resolved pricing `terms` — what the price may be changed
     * to — beside the figures. `publishing` says how many products the package lets the
     * account have on sale (`limit` is null when unlimited). `403` without the right to
     * manage the website.
     *
     * @response array{data: list<WebsiteProductResource>, links: array<string, string|null>, meta: array<string, mixed>, filters: array{search: string|null, status: string|null, category: string|null}, categories: list<array<string, mixed>>, statuses: list<array{value: string, label: string}>, publishing: array{limit: int|null, used: int, remaining: int|null}}
     */
    public function index(WebsiteProductListRequest $request, string $website): AnonymousResourceCollection
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
        ];

        $package = $this->entitlements->activePackage($record->businessAccount)?->package;

        $published = WebsiteProduct::query()
            ->forAccount($record->businessAccount)
            ->published()
            ->count();

        $selections = $this->catalogue->selections($record, $filters)
            ->through(fn (WebsiteProduct $selection) => $this->catalogue->row($selection, $package));

        return WebsiteProductResource::collection($selections)->additional([
            'filters' => $filters,
            'categories' => $this->catalogue->categories($record),
            'statuses' => array_map(fn (WebsiteProductStatus $status) => [
                'value' => $status->value,
                'label' => __('website.product_statuses.'.$status->value),
            ], WebsiteProductStatus::cases()),
            'publishing' => [
                'limit' => $this->entitlements->limit($record->businessAccount, PackageFeature::ProductPublishLimit),
                'used' => $published,
                'remaining' => $this->entitlements->remaining(
                    $record->businessAccount,
                    PackageFeature::ProductPublishLimit,
                    $published,
                ),
            ],
        ]);
    }

    /**
     * Select a product
     *
     * Chooses a dropshipping catalogue product for this storefront and returns the
     * selection (`201`). A product the account may not sell is `404`, never a refusal
     * that confirms it exists. `403` without the right to manage the website; `422`
     * when it is already selected or cannot be sold here.
     */
    public function store(SelectWebsiteProductRequest $request, string $website, SelectProductForWebsite $select): JsonResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        // Found through the eligibility query rather than by identifier alone: a
        // product this account may not see is a 404 (§12, §31.3).
        /** @var Product|null $product */
        $product = $this->eligibility
            ->query($record->businessAccount, SalesChannel::Dropshipping)
            ->where('public_id', $request->validated('product'))
            ->first();

        abort_if($product === null, 404);

        $selection = $this->attempt(fn () => $select->handle($record, $product, $this->person($request)), 'product');

        return (new WebsiteProductResource($this->rowFor($record, $selection)))->response()->setStatusCode(201);
    }

    /**
     * Update a selection
     *
     * Prices, places and describes one selection; only the keys sent change. Prices are
     * flat Taka decimal strings and are checked against the product's bounds on the
     * server. Returns the selection. `403` without the right to manage the website,
     * `404` for a selection that is not this website's, `422` for a price outside the
     * bounds or a locked field.
     */
    public function update(UpdateWebsiteProductRequest $request, string $website, string $selection, UpdateWebsiteProduct $update): WebsiteProductResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $chosen = $this->selectionFor($record, $selection);

        $this->attempt(fn () => $update->handle($chosen, $this->person($request), $request->validated()), 'product');

        return new WebsiteProductResource($this->rowFor($record, $chosen));
    }

    /**
     * Publish or unpublish a product
     *
     * Puts the product on sale, or takes it off, and returns the selection. Publishing is
     * limited by the package (`publishing` on the list). `403` without the right to
     * manage the website, `404` for another website's selection, `422` when it may not
     * be published.
     */
    public function updatePublication(PublishWebsiteProductRequest $request, string $website, string $selection, SetWebsiteProductPublication $publication): WebsiteProductResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $chosen = $this->selectionFor($record, $selection);
        $person = $this->person($request);

        $this->attempt(fn () => $request->boolean('published')
            ? $publication->publish($chosen, $person)
            : $publication->unpublish($chosen, $person), 'product');

        return new WebsiteProductResource($this->rowFor($record, $chosen));
    }

    /**
     * Stop selling a product
     *
     * Removes the selection from this storefront. The catalogue product is untouched.
     * `403` without the right to manage the website, `404` for another website's
     * selection.
     */
    public function destroy(Request $request, string $website, string $selection): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $this->selectionFor($record, $selection)->delete();

        return response()->noContent();
    }

    protected function selectionFor(Website $website, string $selection): WebsiteProduct
    {
        /** @var WebsiteProduct|null $record */
        $record = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('public_id', $selection)
            ->with(['website.businessAccount', 'product'])
            ->first();

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(Website $website, WebsiteProduct $selection): array
    {
        $selection->refresh()->load([
            'product:id,public_id,name,sku,slug,status',
            'product.media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE)->orderBy('position')->limit(1),
            'websiteCategory:id,public_id,name',
        ]);

        $package = $this->entitlements->activePackage($website->businessAccount)?->package;

        return $this->catalogue->row($selection, $package);
    }
}
