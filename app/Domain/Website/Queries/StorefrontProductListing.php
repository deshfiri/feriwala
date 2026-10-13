<?php

namespace App\Domain\Website\Queries;

use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Website\Actions\RefreshWebsiteBestSellers;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finding products in a storefront's catalogue: search, filters, sorting and
 * facets (contract §5.1, P2 C1, C2, H7).
 *
 * **Everything starts from {@see StorefrontSelections}**, so every filter
 * narrows what this website already has on sale and nothing can widen it: no
 * filter reaches another website's selections or a product the owner may not
 * sell. Search is `ILIKE` with the pattern characters escaped, as a bound
 * parameter (H7).
 *
 * Prices are the selling price at one instant, by the same rule as
 * {@see WebsiteProduct::sellingPrice()} — what the payload shows and what an
 * order is charged — so a `price_max` filter and a `price_asc` sort agree with
 * the figures on the page.
 *
 * @phpstan-type Filters array{
 *     q?: string,
 *     price_min?: Money,
 *     price_max?: Money,
 *     category?: string,
 *     brand?: string,
 *     attributes?: array<string, string>,
 *     in_stock?: bool,
 *     featured?: bool,
 *     flash_deal?: bool,
 *     updated_since?: CarbonImmutable,
 * }
 */
class StorefrontProductListing
{
    /**
     * The sorts a storefront may ask for. `best_selling` reads the 30-day
     * summary {@see RefreshWebsiteBestSellers} keeps (C3).
     */
    public const SORTS = ['featured', 'newest', 'price_asc', 'price_desc', 'name', 'best_selling'];

    /** Most attribute filters in one request. */
    public const MAX_ATTRIBUTE_FILTERS = 5;

    /** Facet bounds: brands, attributes, and values under one attribute. */
    public const MAX_BRANDS = 200;

    public const MAX_ATTRIBUTES = 50;

    public const MAX_VALUES = 100;

    public function __construct(
        protected StorefrontSelections $selections,
        protected StockAvailability $stock,
    ) {}

    /**
     * This website's selections on sale, narrowed by the filters.
     *
     * @param  Filters  $filters
     * @return Builder<WebsiteProduct>
     */
    public function filtered(Website $website, array $filters, CarbonImmutable $at): Builder
    {
        $query = $this->selections->query($website);

        if (isset($filters['updated_since'])) {
            $since = $filters['updated_since'];

            $query->where(fn (Builder $inner) => $inner
                ->where('website_products.updated_at', '>=', $since)
                ->orWhereHas('product', fn (Builder $product) => $product->where('updated_at', '>=', $since)));
        }

        if (isset($filters['q'])) {
            $pattern = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $inner) => $inner
                ->whereIn('website_products.product_id', fn (QueryBuilder $products) => $products
                    ->select('products.id')
                    ->from('products')
                    ->where('products.name', 'ilike', $pattern)
                    ->orWhere('products.sku', 'ilike', $pattern)
                    ->orWhereIn('products.id', fn (QueryBuilder $variants) => $variants
                        ->select('product_variants.product_id')
                        ->from('product_variants')
                        ->where('product_variants.is_active', true)
                        ->where('product_variants.sku', 'ilike', $pattern)))
                ->orWhere('website_products.promo_title', 'ilike', $pattern));
        }

        if (isset($filters['price_min']) || isset($filters['price_max'])) {
            [$price, $bindings] = WebsiteProduct::effectivePriceSql($at);

            foreach (['price_min' => '>=', 'price_max' => '<='] as $filter => $operator) {
                if (isset($filters[$filter])) {
                    $query->where('website_products.currency_code', $filters[$filter]->currency->value)
                        ->whereRaw("{$price} {$operator} ?", [...$bindings, $filters[$filter]->amount]);
                }
            }
        }

        if (isset($filters['category'])) {
            $this->inCategory($query, $website, $filters['category']);
        }

        if (isset($filters['brand'])) {
            $brand = $filters['brand'];

            $query->whereIn('website_products.product_id', fn (QueryBuilder $products) => $products
                ->select('products.id')
                ->from('products')
                ->join('brands', 'brands.id', '=', 'products.brand_id')
                ->where(fn (QueryBuilder $match) => $match->where('brands.slug', $brand)->orWhere('brands.public_id', $brand)));
        }

        foreach ($filters['attributes'] ?? [] as $attribute => $value) {
            // Each pair on the same variation is not required: a product
            // matches when an active variation of it has each value asked for.
            $query->whereIn('website_products.product_id', fn (QueryBuilder $variants) => $variants
                ->select('product_variants.product_id')
                ->from('product_variants')
                ->join('product_variant_values', 'product_variant_values.product_variant_id', '=', 'product_variants.id')
                ->join('product_attribute_values', 'product_attribute_values.id', '=', 'product_variant_values.product_attribute_value_id')
                ->join('product_attributes', 'product_attributes.id', '=', 'product_attribute_values.product_attribute_id')
                ->where('product_variants.is_active', true)
                ->where('product_attributes.slug', $attribute)
                ->where('product_attribute_values.value', $value));
        }

        if (isset($filters['in_stock'])) {
            [$inStock, $bindings] = $this->stock->inStockSql('website_products.product_id', $website->businessAccount);

            $query->whereRaw(($filters['in_stock'] ? '' : 'NOT ').$inStock, $bindings);
        }

        if (isset($filters['featured'])) {
            $query->where('website_products.is_featured', $filters['featured']);
        }

        if (isset($filters['flash_deal'])) {
            [$applies, $bindings] = WebsiteProduct::promotionAppliesSql($at);
            $flashDeal = "({$applies} AND website_products.promotion_ends_at IS NOT NULL)";

            $query->whereRaw(($filters['flash_deal'] ? '' : 'NOT ').$flashDeal, $bindings);
        }

        return $query;
    }

    /**
     * The filtered selections in a sort's order, every sort ending on the
     * selection's id so each page boundary is exact (contract §4.4).
     *
     * Computed columns are selected inside and ordered outside, so cursor
     * pagination compares plain columns.
     *
     * @param  Builder<WebsiteProduct>  $filtered
     * @return Builder<WebsiteProduct>
     */
    public function sorted(Builder $filtered, string $sort, CarbonImmutable $at): Builder
    {
        [$price, $bindings] = WebsiteProduct::effectivePriceSql($at);

        $inner = $filtered
            ->join('products as listed_product', 'listed_product.id', '=', 'website_products.product_id')
            ->select('website_products.*')
            ->selectRaw("{$price} as effective_price", $bindings)
            ->selectRaw('listed_product.name as product_name')
            ->selectRaw('COALESCE(website_products.published_at, website_products.created_at) as listed_at')
            ->selectRaw('website_products.is_featured::int as featured_rank');

        // The summary, by primary key, only when it is asked for. A selection
        // with no row sold nothing in the window: zero, then id order.
        if ($sort === 'best_selling') {
            $inner->leftJoin('website_product_sales as best_seller', 'best_seller.website_product_id', '=', 'website_products.id')
                ->selectRaw('COALESCE(best_seller.net_units, 0) as sold_units')
                ->selectRaw('COALESCE(best_seller.order_count, 0) as sold_orders');
        }

        $outer = WebsiteProduct::query()->fromSub($inner, 'website_products');

        match ($sort) {
            'featured' => $outer->orderByDesc('featured_rank')->orderBy('display_order'),
            'newest' => $outer->orderByDesc('listed_at'),
            'price_asc' => $outer->orderBy('effective_price'),
            'price_desc' => $outer->orderByDesc('effective_price'),
            'name' => $outer->orderBy('product_name'),
            'best_selling' => $outer->orderByDesc('sold_units')->orderByDesc('sold_orders'),
            default => throw new InvalidArgumentException("Unknown storefront sort [{$sort}]."),
        };

        return $outer->orderBy('id');
    }

    /**
     * What a storefront can filter this website's catalogue by (C2): its
     * brands, its variation attributes and their values, and its price range,
     * counted over the selections the filters leave. Bounded however large
     * the catalogue.
     *
     * @param  Builder<WebsiteProduct>  $filtered
     * @return array{
     *     brands: list<array{id: string, slug: string, name: string, products: int}>,
     *     attributes: list<array{slug: string, name: string, values: list<array{value: string, products: int}>}>,
     *     price_range: array{minimum: Money, maximum: Money}|null,
     * }
     */
    public function facets(Builder $filtered, CarbonImmutable $at): array
    {
        $products = (clone $filtered)->select('website_products.product_id');

        $brands = array_values(DB::table('products')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->whereIn('products.id', $products->toBase())
            ->groupBy('brands.id', 'brands.public_id', 'brands.slug', 'brands.name')
            ->orderBy('brands.name')
            ->orderBy('brands.id')
            ->limit(self::MAX_BRANDS)
            ->get(['brands.public_id', 'brands.slug', 'brands.name', DB::raw('COUNT(DISTINCT products.id) as products')])
            ->map(fn (object $brand) => [
                'id' => (string) $brand->public_id,
                'slug' => (string) $brand->slug,
                'name' => (string) $brand->name,
                'products' => (int) $brand->products,
            ])
            ->all());

        $rows = DB::table('product_variants')
            ->join('product_variant_values', 'product_variant_values.product_variant_id', '=', 'product_variants.id')
            ->join('product_attribute_values', 'product_attribute_values.id', '=', 'product_variant_values.product_attribute_value_id')
            ->join('product_attributes', 'product_attributes.id', '=', 'product_attribute_values.product_attribute_id')
            ->where('product_variants.is_active', true)
            ->whereIn('product_variants.product_id', $products->toBase())
            ->groupBy(
                'product_attributes.id', 'product_attributes.slug', 'product_attributes.name', 'product_attributes.sort_order',
                'product_attribute_values.value', 'product_attribute_values.sort_order',
            )
            ->orderBy('product_attributes.sort_order')
            ->orderBy('product_attributes.slug')
            ->orderBy('product_attribute_values.sort_order')
            ->orderBy('product_attribute_values.value')
            ->limit(self::MAX_ATTRIBUTES * self::MAX_VALUES)
            ->get([
                'product_attributes.slug',
                'product_attributes.name',
                'product_attribute_values.value',
                DB::raw('COUNT(DISTINCT product_variants.product_id) as products'),
            ]);

        $attributes = [];

        foreach ($rows as $row) {
            $slug = (string) $row->slug;

            if (! isset($attributes[$slug]) && count($attributes) >= self::MAX_ATTRIBUTES) {
                continue;
            }

            $attributes[$slug] ??= ['slug' => $slug, 'name' => (string) $row->name, 'values' => []];

            if (count($attributes[$slug]['values']) < self::MAX_VALUES) {
                $attributes[$slug]['values'][] = ['value' => (string) $row->value, 'products' => (int) $row->products];
            }
        }

        return [
            'brands' => $brands,
            'attributes' => array_values($attributes),
            'price_range' => $this->priceRange($filtered, $at),
        ];
    }

    /**
     * The lowest and highest selling price among the filtered selections, in
     * the platform currency, or null when there are none.
     *
     * @param  Builder<WebsiteProduct>  $filtered
     * @return array{minimum: Money, maximum: Money}|null
     */
    protected function priceRange(Builder $filtered, CarbonImmutable $at): ?array
    {
        [$price, $bindings] = WebsiteProduct::effectivePriceSql($at);
        $currency = Currency::base();

        $range = (clone $filtered)
            ->where('website_products.currency_code', $currency->value)
            ->toBase()
            ->selectRaw("MIN({$price}) as minimum, MAX({$price}) as maximum", [...$bindings, ...$bindings])
            ->first();

        if ($range === null || $range->minimum === null) {
            return null;
        }

        return [
            'minimum' => Money::fromDecimal((string) $range->minimum, $currency),
            'maximum' => Money::fromDecimal((string) $range->maximum, $currency),
        ];
    }

    /**
     * The shop's own category where one matches, otherwise the catalogue
     * category and its subcategories for selections the shop has not placed —
     * exactly the category the payload shows (contract §5.1). A category that
     * matches neither leaves nothing.
     *
     * @param  Builder<WebsiteProduct>  $query
     */
    protected function inCategory(Builder $query, Website $website, string $category): void
    {
        $shopCategory = WebsiteCategory::query()
            ->where('website_id', $website->id)
            ->where(fn (Builder $match) => $match->where('slug', $category)->orWhere('public_id', $category))
            ->value('id');

        if ($shopCategory !== null) {
            $query->where('website_products.website_category_id', $shopCategory);

            return;
        }

        /** @var Category|null $catalogueCategory */
        $catalogueCategory = Category::query()
            ->where('is_active', true)
            ->where(fn (Builder $match) => $match->where('slug', $category)->orWhere('public_id', $category))
            ->first();

        if ($catalogueCategory === null) {
            $query->whereRaw('FALSE');

            return;
        }

        $query->whereNull('website_products.website_category_id')
            ->whereIn('website_products.product_id', fn (QueryBuilder $products) => $products
                ->select('products.id')
                ->from('products')
                ->whereIn('products.category_id', $catalogueCategory->descendantIds()));
    }
}
