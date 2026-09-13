<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveProductRequest;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The central product catalogue, from the administrator's side (§11, §12).
 *
 * Every action asks {@see CatalogPolicy} first. A business account holder holds
 * no platform permission, so each of these is a 403 for a partner — the §12
 * requirement that a regular user cannot create or modify a product is a
 * refusal here, not an absent button.
 *
 * The editor is a page rather than a dialog: a product carries enough fields
 * that a modal would scroll inside a scroll, and the variations, media and
 * pricing that later tasks add all belong beside it.
 */
class ProductController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected ManageProducts $products,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $search = trim($request->string('search')->toString());
        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $products = Product::query()
            ->with(['category:id,name', 'brand:id,name'])
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'ilike', $pattern)
                    ->orWhere('sku', 'ilike', $pattern)
                    ->orWhere('barcode', 'ilike', $pattern),
            ))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product) => $this->row($product));

        return Inertia::render('admin/catalog/products/index', [
            'products' => $products,
            'can' => $this->abilities($actor),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        return Inertia::render('admin/catalog/products/form', [
            'product' => null,
            'options' => $this->options(),
            'can' => $this->abilities($actor),
        ]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        $product = $this->products->create($actor, $request->validated());

        return redirect()
            ->route('admin.catalog.products.edit', $product->public_id)
            ->with('success', __('catalog.products.created', ['name' => $product->name]));
    }

    public function edit(Request $request, string $product): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        return Inertia::render('admin/catalog/products/form', [
            'product' => $this->detail($this->product($product)),
            'options' => $this->options(),
            'can' => $this->abilities($actor),
        ]);
    }

    public function update(SaveProductRequest $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $updated = $this->products->update($actor, $this->product($product), $request->validated());

        return back()->with('success', __('catalog.products.updated', ['name' => $updated->name]));
    }

    public function destroy(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->products->delete($actor, $this->product($product));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['product' => $refused->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.products.index')
            ->with('success', __('catalog.products.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Product $product): array
    {
        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category->name,
            'brand' => $product->brand?->name,
            'wholesale_price' => $product->wholesale_price_minor->jsonSerialize(),
            'status' => $product->status,
            'updated_at' => $product->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Product $product): array
    {
        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'category_id' => $product->category->public_id,
            'brand_id' => $product->brand?->public_id,

            // Both the integer the form edits and the server's own rendering of
            // it, so the page never formats money itself (§36.1).
            'base_cost_minor' => $product->base_cost_minor->minorUnits,
            'wholesale_price_minor' => $product->wholesale_price_minor->minorUnits,
            'base_cost' => $product->base_cost_minor->jsonSerialize(),
            'wholesale_price' => $product->wholesale_price_minor->jsonSerialize(),

            'status' => $product->status,
            'updated_at' => $product->updated_at->toIso8601String(),
        ];
    }

    /**
     * What the editor's selects offer.
     *
     * Switched-off categories and brands are included and labelled rather than
     * dropped: a product already filed under one must still show where it is,
     * and a draft may be prepared before its range goes live.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        $categories = Category::query()
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'categories' => $categories
                ->sortBy(fn (Category $category) => [
                    $category->parent->sort_order ?? $category->sort_order,
                    $category->parent_id ?? $category->id,
                    $category->parent_id === null ? 0 : 1,
                    $category->sort_order,
                ])
                ->map(fn (Category $category) => [
                    'value' => $category->public_id,
                    'label' => $category->parent === null
                        ? $category->name
                        : $category->parent->name.' › '.$category->name,
                    'is_available' => $category->isAvailable(),
                ])
                ->values()
                ->all(),

            'brands' => Brand::query()
                ->orderBy('name')
                ->get(['public_id', 'name', 'is_active'])
                ->map(fn (Brand $brand) => [
                    'value' => $brand->public_id,
                    'label' => $brand->name,
                    'is_available' => $brand->is_active,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(User $actor): array
    {
        return [
            'create' => CatalogPolicy::canCreate($actor),
            'edit' => CatalogPolicy::canEdit($actor),
            'delete' => CatalogPolicy::canDelete($actor),
        ];
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::query()
            ->with(['category', 'brand'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $product;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
