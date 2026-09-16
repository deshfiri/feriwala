<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Website\Actions\ManageWebsiteCategories;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Queries\WebsiteCatalogue;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A storefront's own categories (§15, P5-4).
 *
 * The partner's arrangement of their shop, not the central catalogue's
 * (§11.3, §12). Removing one loses the placement of what it held and nothing
 * else: an arrangement is not the goods.
 */
class WebsiteCategoryController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
        protected WebsiteCatalogue $catalogue,
    ) {}

    public function index(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return Inertia::render('websites/categories', [
            'website' => $this->overview->summary($record),
            'categories' => $this->catalogue->categories($record),
        ]);
    }

    public function store(Request $request, string $website, ManageWebsiteCategories $categories): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $this->attempt(fn () => $categories->create($record, $validated['name']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.category_added')]);

        return back();
    }

    public function update(
        Request $request,
        string $website,
        string $category,
        ManageWebsiteCategories $categories,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->attempt(fn () => $categories->update($this->categoryFor($record, $category), $validated));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.category_saved')]);

        return back();
    }

    /**
     * The whole arrangement in one write (P5-4).
     */
    public function reorder(Request $request, string $website, ManageWebsiteCategories $categories): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'size:26'],
        ]);

        $categories->reorder($record, array_values($validated['order']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.categories_reordered')]);

        return back();
    }

    public function destroy(
        Request $request,
        string $website,
        string $category,
        ManageWebsiteCategories $categories,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $categories->delete($this->categoryFor($record, $category));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.category_removed')]);

        return back();
    }

    /**
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step): void
    {
        try {
            $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
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

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $website);

        abort_if($record === null, 404);

        return $record;
    }
}
