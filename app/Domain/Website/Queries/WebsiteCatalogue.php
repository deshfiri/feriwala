<?php

namespace App\Domain\Website\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Package\Models\Package;
use App\Domain\Website\Data\WebsitePricingTerms;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * What one storefront sells, as its owner sees it (§15, §15.1, P5-2–P5-7).
 *
 * One place, so the selection list and every action that renders after it agree
 * about what a row says. Each row carries the **resolved pricing terms** beside
 * the figures, because a partner looking at a price needs to know what they are
 * allowed to change it to — and the same terms are re-checked server-side on
 * every save, so the screen showing them is a convenience rather than the rule.
 *
 * Costs are never here. What Feriwala paid for a product is not on any surface
 * a partner reads (D12); the bounds are selling-side figures an administrator
 * set.
 */
class WebsiteCatalogue
{
    /**
     * Resolved terms, memoised per product for the life of this request.
     *
     * A page of twenty selections would otherwise resolve the same global rule
     * twenty times.
     *
     * @var array<int, WebsitePricingTerms>
     */
    protected array $terms = [];

    public function __construct(
        protected ResolveWebsitePriceRule $pricing,
        protected ProductMediaStore $media,
    ) {}

    /**
     * One storefront's selections, filtered and paged.
     *
     * @param  array{search?: string|null, status?: string|null, category?: string|null}  $filters
     * @return LengthAwarePaginator<int, WebsiteProduct>
     */
    public function selections(Website $website, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;
        $category = $filters['category'] ?? null;

        return WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->with([
                'product:id,public_id,name,sku,slug,status',
                'product.media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE)->orderBy('position')->limit(1),
                'websiteCategory:id,public_id,name',
            ])
            ->when($status !== null && $status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($category !== null && $category !== '', fn (Builder $query) => $query
                ->whereHas('websiteCategory', fn (Builder $inner) => $inner->where('public_id', $category)))
            ->when($search !== '', fn (Builder $query) => $query
                ->whereHas('product', fn (Builder $inner) => $inner
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('sku', 'ilike', '%'.$search.'%')))
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One selection, as a row.
     *
     * @return array<string, mixed>
     */
    public function row(WebsiteProduct $selection, ?Package $package = null): array
    {
        $product = $selection->product;
        $terms = $this->termsFor($product, $package);
        $image = $product->relationLoaded('media') ? $product->media->first() : null;

        return [
            'id' => $selection->public_id,
            'product' => [
                'id' => $product->public_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image' => $image === null ? null : $this->media->url($image->path),
            ],
            'status' => $selection->status->value,
            'status_label' => __('website.product_statuses.'.$selection->status->value),
            'sync_status' => $selection->sync_status->value,
            'sync_label' => __('website.sync_statuses.'.$selection->sync_status->value),
            'last_synced_at' => $selection->last_synced_at?->toIso8601String(),
            'sync_error' => $selection->sync_error,
            'price' => $selection->price?->jsonSerialize(),
            'promotional_price' => $selection->promotional_price?->jsonSerialize(),
            'promo_title' => $selection->promo_title,
            'marketing_description' => $selection->marketing_description,
            'is_featured' => $selection->is_featured,
            'display_order' => $selection->display_order,
            'category' => $selection->websiteCategory === null ? null : [
                'id' => $selection->websiteCategory->public_id,
                'name' => $selection->websiteCategory->name,
            ],
            'terms' => $terms->toArray(),
            'published_at' => $selection->published_at?->toIso8601String(),
        ];
    }

    /**
     * The storefront's own arrangement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(Website $website): array
    {
        return WebsiteCategory::query()
            ->where('website_id', $website->id)
            ->arranged()
            ->withCount('products')
            ->get()
            ->map(fn (WebsiteCategory $category) => [
                'id' => $category->public_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'position' => $category->position,
                'is_active' => $category->is_active,
                'products_count' => (int) ($category->getAttribute('products_count') ?? 0),
            ])
            ->all();
    }

    /**
     * The terms for one product, resolved once per request.
     */
    public function termsFor(Product $product, ?Package $package = null): WebsitePricingTerms
    {
        return $this->terms[$product->id] ??= $this->pricing->for($product, $package);
    }
}
