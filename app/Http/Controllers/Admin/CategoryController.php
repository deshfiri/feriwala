<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageCategories;
use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveCategoryRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The category tree (§11.3).
 *
 * Every write asks {@see CatalogPolicy} first. §12 makes catalogue authorship a
 * platform privilege, and a business account holder holds no platform
 * permissions — so a partner reaching any of these gets a 403 rather than a
 * screen with the buttons hidden, which is the difference between enforcement
 * and decoration.
 *
 * Disabled categories are listed alongside live ones rather than dropped. An
 * administrator asking "why has that range stopped appearing on the storefronts"
 * needs to see it switched off; a list that hides it answers nothing.
 */
class CategoryController extends Controller
{
    public function __construct(
        protected ManageCategories $categories,
        protected CatalogImageStore $images,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $categories = Category::query()
            ->with('parent')
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /*
         * What switching a category off takes off partners' catalogues: its own
         * products and its subcategories'. Counted from the rows already loaded,
         * since the tree is one level of subcategories deep.
         */
        $branchCounts = $categories->mapWithKeys(fn (Category $category) => [
            $category->id => (int) $category->products_count + (int) $categories
                ->where('parent_id', $category->id)
                ->sum('products_count'),
        ]);

        return Inertia::render('admin/catalog/categories', [
            'categories' => $categories
                ->map(fn (Category $category) => [
                    ...$this->summary($category),
                    'products_in_branch' => (int) $branchCounts->get($category->id, 0),
                ])
                ->values()
                ->all(),

            'can' => [
                'create' => CatalogPolicy::canCreate($actor),
                'edit' => CatalogPolicy::canEdit($actor),
                'delete' => CatalogPolicy::canDelete($actor),
                'purge' => CatalogPolicy::canForceDelete($actor),
            ],

            // Stated to the form so the help text cannot drift from the check.
            'limits' => [
                'image_max_kb' => (int) (CatalogImageStore::MAX_BYTES / 1024),
                'image_types' => CatalogImageStore::ACCEPTED_MIME_TYPES,
            ],
        ]);
    }

    public function store(SaveCategoryRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        try {
            $category = $this->categories->create($actor, $this->attributes($request), $request->file('image'));
        } catch (CatalogRefused $refused) {
            return $this->refuse($refused);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.categories.created', ['name' => $category->name])]);

        return back();
    }

    public function update(SaveCategoryRequest $request, string $category): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        try {
            $updated = $this->categories->update(
                $actor,
                $this->category($category),
                $this->attributes($request),
                $request->file('image'),
            );
        } catch (CatalogRefused $refused) {
            return $this->refuse($refused);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.categories.updated', ['name' => $updated->name])]);

        return back();
    }

    /**
     * Switch a category on or off (§11.3).
     *
     * Its own endpoint rather than a field on the edit form: turning a range off
     * takes it and its subcategories off every partner storefront, and that is
     * a decision somebody makes deliberately rather than in passing while
     * correcting a typo.
     */
    public function toggle(Request $request, string $category): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $updated = $this->categories->setActive(
            $actor,
            $this->category($category),
            (bool) $validated['is_active'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(
            $updated->is_active ? 'catalog.categories.enabled' : 'catalog.categories.disabled',
            ['name' => $updated->name],
        )]);

        return back();
    }

    /**
     * Put one branch of the tree in a new order (§11.3).
     *
     * The whole sibling list in one request — the top-level categories when
     * `parent_id` is blank — so a reorder is one decision rather than a series
     * of swaps somebody could read half-way through.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'string', Rule::exists(Category::class, 'public_id')],
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*' => ['required', 'string', 'distinct', 'max:40'],
        ]);

        $parent = filled($validated['parent_id'] ?? null) ? $this->category((string) $validated['parent_id']) : null;

        $this->categories->reorder($actor, $parent, array_values($validated['order']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.categories.reordered')]);

        return back();
    }

    public function destroy(Request $request, string $category): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->categories->delete($actor, $this->category($category));
        } catch (CatalogRefused $refused) {
            // An answer to what was asked — "this still holds eleven products" —
            // rather than a failure of the request.
            return back()->withErrors(['category' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.categories.deleted')]);

        return back();
    }

    /**
     * Delete a category with all its subcategories, after the Super Admin has
     * confirmed with their password. Refused while any Product still sits in
     * the branch.
     */
    public function purge(Request $request, string $category): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canForceDelete($actor), 403);

        $request->validate(['password' => ['required', 'string', 'current_password']]);

        try {
            $deleted = $this->categories->purge($actor, $this->category($category));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['category' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.categories.purged', ['count' => $deleted])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Category $category): array
    {
        return [
            'id' => $category->public_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent?->public_id,
            'parent_name' => $category->parent?->name,
            'depth' => $category->depth(),
            'description' => $category->description,

            'image_url' => $this->images->url($category->image_path),
            'image_alt' => $category->image_alt,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
            'meta_keywords' => $category->meta_keywords,

            'is_active' => $category->is_active,

            /*
             * Whether it is actually reachable, which is not the same question.
             * A live subcategory under a disabled parent is off, and a screen
             * that showed it as on would be lying about what customers see.
             */
            'is_available' => $category->isAvailable(),

            'sort_order' => $category->sort_order,

            // Products filed directly here — the reason a delete is refused.
            'products_count' => (int) $category->products_count,
        ];
    }

    protected function category(string $publicId): Category
    {
        /** @var Category $category */
        $category = Category::query()->where('public_id', $publicId)->firstOrFail();

        return $category;
    }

    /**
     * The validated fields, with the file left to its own argument.
     *
     * `remove_image` is read through `boolean()` because a multipart form sends
     * it as the string "1", and the action compares it strictly — a loose
     * truthiness check is how "0" ends up deleting somebody's image.
     *
     * @return array<string, mixed>
     */
    protected function attributes(SaveCategoryRequest $request): array
    {
        return [
            ...Arr::except($request->validated(), ['image']),
            'remove_image' => $request->boolean('remove_image'),
        ];
    }

    protected function refuse(CatalogRefused $refused): RedirectResponse
    {
        throw ValidationException::withMessages(['parent_id' => $refused->getMessage()]);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
