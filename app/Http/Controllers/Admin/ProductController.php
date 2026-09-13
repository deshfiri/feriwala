<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageProductMedia;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductMediaStore;
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
        protected ProductMediaStore $mediaStore,
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

            // Media and variations are added once the product exists to hang
            // them on.
            'media' => [],
            'media_limits' => $this->mediaLimits(),
            'variants' => [],
            'attributes' => [],
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

        $record = $this->product($product);

        return Inertia::render('admin/catalog/products/form', [
            'product' => $this->detail($record),
            'options' => $this->options(),
            'can' => $this->abilities($actor),

            // Images and videos, in the order storefronts show them (§11.1).
            'media' => $record->media()
                ->with('variant.values.attribute')
                ->get()
                ->map(fn (ProductMedia $media) => $this->mediaRow($media))
                ->all(),
            'media_limits' => $this->mediaLimits(),

            // Variations beside the product they belong to (§11.1).
            'variants' => $record->variants()
                ->with(['values.attribute', 'product'])
                ->get()
                ->map(fn (ProductVariant $variant) => $this->variant($variant))
                ->all(),

            'attributes' => ProductAttribute::query()
                ->with('values')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductAttribute $attribute) => [
                    'id' => $attribute->public_id,
                    'name' => $attribute->name,
                    'values' => $attribute->values
                        ->map(fn (ProductAttributeValue $value) => [
                            'id' => $value->public_id,
                            'value' => $value->value,
                        ])
                        ->all(),
                ])
                ->all(),
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
     * One image or video as the editor shows it — the storefront contract's
     * url, alt, position and type, plus what an administrator needs beside it.
     *
     * @return array<string, mixed>
     */
    protected function mediaRow(ProductMedia $media): array
    {
        return [
            'id' => $media->public_id,
            'type' => $media->type,
            'url' => $this->mediaStore->url($media->path),
            'alt_text' => $media->alt_text,
            'position' => $media->position,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'width' => $media->width,
            'height' => $media->height,
            'variant_id' => $media->variant?->public_id,
            'variant_label' => $media->variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
        ];
    }

    /**
     * The limits the upload form states — the same figures the server enforces,
     * never above what PHP accepts.
     *
     * @return array<string, mixed>
     */
    protected function mediaLimits(): array
    {
        return [
            'image_types' => ProductMediaStore::IMAGE_TYPES,
            'video_types' => ProductMediaStore::VIDEO_TYPES,
            // Strings, already formatted: "2", "2.5". A float in a prop comes
            // back from JSON as whatever it happens to round-trip to.
            'image_max_mb' => $this->megabytes(ProductMediaStore::maxBytesFor(ProductMedia::TYPE_IMAGE)),
            'video_max_mb' => $this->megabytes(ProductMediaStore::maxBytesFor(ProductMedia::TYPE_VIDEO)),
            'max_items' => ManageProductMedia::MAX_PER_PRODUCT,
        ];
    }

    public static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.');
    }

    /**
     * One variation as the editor shows it.
     *
     * The effective price is the server's answer to "what does this variant
     * sell at", so the page never decides whether an override applies.
     *
     * @return array<string, mixed>
     */
    protected function variant(ProductVariant $variant): array
    {
        $values = $variant->values
            ->sortBy(fn (ProductAttributeValue $value) => [$value->attribute->sort_order, $value->attribute->name])
            ->values();

        return [
            'id' => $variant->public_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'label' => $values->pluck('value')->implode(' / '),
            'values' => $values
                ->map(fn (ProductAttributeValue $value) => [
                    'attribute' => $value->attribute->name,
                    'value' => $value->value,
                ])
                ->all(),
            'wholesale_price_minor' => $variant->wholesale_price_minor?->minorUnits,
            'base_cost_minor' => $variant->base_cost_minor?->minorUnits,
            'wholesale_price' => $variant->effectiveWholesalePrice()->jsonSerialize(),
            'overrides_price' => $variant->wholesale_price_minor !== null,
            'is_active' => $variant->is_active,
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
