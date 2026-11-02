<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites;

use App\Domain\Website\Actions\ManageWebsiteCategories;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Queries\WebsiteCatalogue;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Websites\Concerns\ResolvesOwnWebsite;
use App\Http\Requests\Mobile\Websites\ReorderWebsiteCategoriesRequest;
use App\Http\Requests\Mobile\Websites\StoreWebsiteCategoryRequest;
use App\Http\Requests\Mobile\Websites\UpdateWebsiteCategoryRequest;
use App\Http\Resources\Mobile\Websites\WebsiteCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A storefront's own categories (§15) — the partner's arrangement of their
 * shop, not the central catalogue's. Removing one loses the placement of what it
 * held and nothing else.
 */
class WebsiteCategoryController extends MobileController
{
    use ResolvesOwnWebsite;

    public function __construct(
        protected WebsiteOverview $overview,
        protected WebsiteCatalogue $catalogue,
    ) {}

    /**
     * List website categories
     *
     * The storefront's categories in their arranged order, with how many products each
     * holds. `403` without the right to manage the website.
     */
    public function index(Request $request, string $website): AnonymousResourceCollection
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return WebsiteCategoryResource::collection($this->catalogue->categories($record));
    }

    /**
     * Add a category
     *
     * Creates a category on this storefront (`201`). `403` without the right to manage
     * the website; `422` for an invalid or duplicate name.
     */
    public function store(StoreWebsiteCategoryRequest $request, string $website, ManageWebsiteCategories $categories): JsonResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $category = $this->attempt(fn () => $categories->create($record, $request->validated('name')));

        return (new WebsiteCategoryResource($this->rowFor($record, $category->public_id)))->response()->setStatusCode(201);
    }

    /**
     * Update a category
     *
     * Renames a category or shows/hides it, and returns it. `404` for another website's
     * category.
     */
    public function update(UpdateWebsiteCategoryRequest $request, string $website, string $category, ManageWebsiteCategories $categories): WebsiteCategoryResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $found = $this->categoryFor($record, $category);

        $this->attempt(fn () => $categories->update($found, $request->validated()));

        return new WebsiteCategoryResource($this->rowFor($record, $found->public_id));
    }

    /**
     * Arrange categories
     *
     * Saves the whole arrangement in one write: send every category's public id in the
     * order wanted. Returns the categories as arranged.
     */
    public function reorder(ReorderWebsiteCategoriesRequest $request, string $website, ManageWebsiteCategories $categories): AnonymousResourceCollection
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $categories->reorder($record, array_values($request->validated('order')));

        return WebsiteCategoryResource::collection($this->catalogue->categories($record));
    }

    /**
     * Remove a category
     *
     * Removes the category; the products it held lose only their placement. `404` for
     * another website's category.
     */
    public function destroy(Request $request, string $website, string $category, ManageWebsiteCategories $categories): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $categories->delete($this->categoryFor($record, $category));

        return response()->noContent();
    }

    protected function categoryFor(Website $website, string $category): WebsiteCategory
    {
        /** @var WebsiteCategory|null $record */
        $record = WebsiteCategory::query()
            ->where('website_id', $website->id)
            ->where('public_id', $category)
            ->first();

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(Website $website, string $publicId): array
    {
        return collect($this->catalogue->categories($website))->firstWhere('id', $publicId) ?? abort(404);
    }
}
