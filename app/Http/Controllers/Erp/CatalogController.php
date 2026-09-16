<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Http\Controllers\Controller;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The central catalogue, from a business account's side (§10, §12, §13).
 *
 * **Read-only, and one screen per channel.** A partner never writes a product
 * (§12), and dropshipping and wholesale are browsed on separate routes through
 * separate queries: each asks {@see ProductEligibility} for that channel, so a
 * product switched on only for wholesale is not on the dropshipping screen and
 * cannot be opened there by typing its address.
 *
 * What each channel shows differs on purpose. Wholesale shows what the account
 * pays — the wholesale price and quantity tiers. Dropshipping shows what the
 * partner is guided to sell at. **Neither ever shows the base cost**, and neither
 * shows the other channel's prices: a dropshipping partner has no use for the
 * wholesale figure, and the storefront contract (§5.1) keeps it from partners'
 * customers for the same reason.
 *
 * A product the account may not see is a 404, not a 403. A refusal would confirm
 * that the product exists, which is itself something the account was not
 * eligible to learn.
 */
class CatalogController extends Controller
{
    use ResolvesBusinessAccount;

    public const PER_PAGE = 24;

    /** @var array<int, string> */
    public const STOCK_FILTERS = ['in_stock', 'out_of_stock'];

    public function __construct(
        protected ProductEligibility $eligibility,
        protected ProductMediaStore $media,
        protected WholesalePriceResolver $prices,
        protected StockAvailability $stock,
    ) {}

    public function wholesale(Request $request): Response
    {
        return $this->browse($request, SalesChannel::Wholesale);
    }

    public function dropshipping(Request $request): Response
    {
        return $this->browse($request, SalesChannel::Dropshipping);
    }

    public function showWholesale(Request $request, string $product): Response
    {
        return $this->show($request, SalesChannel::Wholesale, $product);
    }

    public function showDropshipping(Request $request, string $product): Response
    {
        return $this->show($request, SalesChannel::Dropshipping, $product);
    }

    protected function browse(Request $request, SalesChannel $channel): Response
    {
        $account = $this->businessAccountFor($request);

        $search = trim($request->string('search')->toString());
        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $category = $request->filled('category')
            ? Category::query()->with('children')->where('public_id', $request->string('category')->toString())->first()
            : null;

        $brand = $request->filled('brand')
            ? Brand::query()->where('public_id', $request->string('brand')->toString())->first()
            : null;

        // Stock and price are wholesale filters (§13): a dropshipping partner does
        // not buy the stock and is not charged the wholesale price.
        $wholesale = $channel === SalesChannel::Wholesale;
        $stock = $wholesale && in_array($request->string('stock')->toString(), self::STOCK_FILTERS, true)
            ? $request->string('stock')->toString()
            : null;
        $priceMin = $wholesale ? $this->priceFilter($request, 'price_min') : null;
        $priceMax = $wholesale ? $this->priceFilter($request, 'price_max') : null;

        $query = $this->eligibility->query($account, $channel);

        if ($stock !== null) {
            $query = $this->stock->whereProductStock($query, $account, $stock === 'in_stock');
        }

        $products = $query
            ->with([
                'category:id,name',
                'brand:id,name',
                'media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE),
            ])
            ->withExists('priceTiers')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->where('name', 'ilike', $pattern)->orWhere('sku', 'ilike', $pattern),
            ))
            // A parent category means its subcategories too — that is what a
            // buyer means by choosing it.
            ->when($category !== null, fn (Builder $query) => $query->whereIn('category_id', $category?->descendantIds() ?? []))
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand?->id))
            // The product's own wholesale price, the figure the card shows.
            ->when($priceMin !== null, fn (Builder $query) => $query->where('wholesale_price_minor', '>=', $priceMin))
            ->when($priceMax !== null, fn (Builder $query) => $query->where('wholesale_price_minor', '<=', $priceMax))
            // Featured first (§11.1), newest featured first among them.
            ->orderByDesc('is_featured')
            ->orderByDesc('featured_at')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $stockStates = $wholesale ? $this->stockStates($products->getCollection(), $account) : [];

        $products->through(fn (Product $product) => $this->card($product, $channel, $stockStates[$product->id] ?? null));

        return Inertia::render('catalog/browse', [
            'channel' => $channel->value,
            'facility_allowed' => $this->eligibility->allowsChannel($account, $channel),
            'products' => $products,
            'filters' => [
                'search' => $search,
                'category' => $category?->public_id,
                'brand' => $brand?->public_id,
                'stock' => $stock,
                'price_min' => $priceMin === null ? null : Money::of($priceMin, Currency::BDT)->toDecimal(),
                'price_max' => $priceMax === null ? null : Money::of($priceMax, Currency::BDT)->toDecimal(),
            ],
            'options' => [
                'categories' => Category::query()
                    ->with('parent')
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->filter(fn (Category $option) => $option->isAvailable())
                    ->map(fn (Category $option) => [
                        'value' => $option->public_id,
                        'label' => $option->parent === null ? $option->name : $option->parent->name.' › '.$option->name,
                    ])
                    ->values()
                    ->all(),
                'brands' => Brand::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['public_id', 'name'])
                    ->map(fn (Brand $option) => ['value' => $option->public_id, 'label' => $option->name])
                    ->all(),
            ],
        ]);
    }

    protected function show(Request $request, SalesChannel $channel, string $slug): Response
    {
        $account = $this->businessAccountFor($request);

        /** @var Product|null $product */
        $product = $this->eligibility->query($account, $channel)
            ->where('slug', $slug)
            ->with(['category.parent', 'brand', 'media', 'variants.values.attribute', 'variants.product'])
            ->first();

        abort_if($product === null, 404);

        $wholesale = $channel === SalesChannel::Wholesale;
        $available = $wholesale ? $this->orderableUnits($product, $account) : [];

        $detail = [
            'slug' => $product->slug,
            'name' => $product->name,
            'sku' => $product->sku,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'category' => $product->category->parent === null
                ? $product->category->name
                : $product->category->parent->name.' › '.$product->category->name,
            'brand' => $product->brand?->name,
            'media' => $product->media
                ->map(fn (ProductMedia $item) => [
                    'type' => $item->type,
                    'url' => $this->media->url($item->path),
                    'alt' => $item->alt_text,
                ])
                ->all(),
            'variants' => $product->variants
                ->where('is_active', true)
                ->map(fn (ProductVariant $variant) => [
                    'label' => $variant->values
                        ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                        ->pluck('value')
                        ->implode(' / '),
                    'sku' => $variant->sku,
                    ...($wholesale
                        ? [
                            'wholesale_price' => $variant->effectiveWholesalePrice()->jsonSerialize(),
                            'in_stock' => ($available[$variant->sku] ?? 0) > 0,
                            'available' => $available[$variant->sku] ?? 0,
                            'quantity_pricing' => $this->quantityPricing($product, $variant),
                        ]
                        : []),
                ])
                ->values()
                ->all(),
        ];

        if ($wholesale) {
            $hasVariants = $product->variants->isNotEmpty();

            $detail += [
                'wholesale_price' => $product->wholesale_price_minor->jsonSerialize(),
                'min_order_quantity' => $product->min_order_quantity,
                'max_order_quantity' => $product->max_order_quantity,

                // Resolved by the server at each band's starting quantity, so
                // a band the base price has since undercut shows what is
                // actually charged.
                'quantity_pricing' => $this->quantityPricing($product, null),

                // What this account can order now (P4-2): the product itself, or
                // any of its active variations — each variation carries its own.
                'in_stock' => collect($available)->contains(fn (int $units) => $units > 0),
                'available' => $hasVariants ? null : ($available[$product->sku] ?? 0),
            ];
        } else {
            $detail += $this->sellingGuidance($product);
        }

        /*
         * Related products the account may itself see on this channel — the
         * same eligibility query, restricted to this product's recommendations,
         * so a recommendation never leaks a product the account could not open.
         */
        $relatedIds = $product->relatedProducts()->pluck('products.id')->all();
        $positions = array_flip($relatedIds);

        /** @var Collection<int, Product> $relatedProducts */
        $relatedProducts = $relatedIds === []
            ? collect()
            : $this->eligibility->query($account, $channel)
                ->whereIn('id', $relatedIds)
                ->with(['category:id,name', 'brand:id,name', 'media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE)])
                ->withExists('priceTiers')
                ->get()
                ->sortBy(fn (Product $related) => $positions[$related->id] ?? PHP_INT_MAX)
                ->values();

        $relatedStock = $wholesale ? $this->stockStates($relatedProducts, $account) : [];

        $related = $relatedProducts
            ->map(fn (Product $related) => $this->card($related, $channel, $relatedStock[$related->id] ?? null))
            ->all();

        return Inertia::render('catalog/show', [
            'channel' => $channel->value,
            'product' => $detail,
            'related' => $related,

            /*
             * The storefronts this product could be sold on (§15, P5-1, P5-2).
             *
             * Dropshipping only, because a website sells dropshipped goods;
             * and only the shops that are still open, each saying whether it
             * already sells this. Selecting is a separate, checked act — this
             * is what makes the door reachable from where a partner is already
             * standing.
             */
            'websites' => $channel === SalesChannel::Dropshipping
                ? $this->websitesFor($account, $product)
                : [],

            // What the selection endpoint is told. The product's public
            // identifier, never its key (§34.2).
            'product_id' => $product->public_id,
        ]);
    }

    /**
     * This account's open storefronts, and whether each already sells it.
     *
     * @return array<int, array{id: string, name: string, selected: bool}>
     */
    protected function websitesFor(BusinessAccount $account, Product $product): array
    {
        $websites = Website::query()
            ->forAccount($account)
            ->open()
            ->orderBy('name')
            ->get(['id', 'public_id', 'name']);

        if ($websites->isEmpty()) {
            return [];
        }

        $selected = WebsiteProduct::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->where('product_id', $product->id)
            ->pluck('website_id')
            ->all();

        return $websites
            ->map(fn (Website $website) => [
                'id' => $website->public_id,
                'name' => $website->name,
                'selected' => in_array($website->id, $selected, true),
            ])
            ->values()
            ->all();
    }

    /**
     * How many of each stockable unit this account can order, keyed by SKU
     * (P4-2): the product itself when it has no variations, otherwise its active
     * variations. Read through {@see StockAvailability}, so the figure is stock in
     * active warehouses plus what is allocated to this account, and nobody
     * else's allocation.
     *
     * @return array<string, int>
     */
    protected function orderableUnits(Product $product, BusinessAccount $account): array
    {
        $units = $product->variants->isEmpty()
            ? [['sku' => $product->sku, 'product_id' => $product->id, 'variant_id' => null]]
            : $product->variants
                ->where('is_active', true)
                ->map(fn (ProductVariant $variant) => ['sku' => $variant->sku, 'product_id' => $product->id, 'variant_id' => $variant->id])
                ->values()
                ->all();

        return array_map(
            fn (array $answer) => $answer['quantity'],
            $this->stock->forUnits($units, $account),
        );
    }

    /**
     * Quantity bands for the product or one variation, each resolved by the
     * server at its starting quantity.
     *
     * @return array<int, array{min_quantity: int, unit_price: array<string, mixed>}>
     */
    protected function quantityPricing(Product $product, ?ProductVariant $variant): array
    {
        return $this->prices->tiersFor($product, $variant)
            ->map(fn (ProductPriceTier $tier) => [
                'min_quantity' => $tier->min_quantity,
                'unit_price' => $this->prices->unitPrice($product, $variant, $tier->min_quantity)->jsonSerialize(),
            ])
            ->values()
            ->all();
    }

    /**
     * A wholesale price bound typed in taka, as minor units — or null when it is
     * missing or not a sensible amount, so a mistyped bound narrows nothing.
     */
    protected function priceFilter(Request $request, string $key): ?int
    {
        $value = trim($request->string($key)->toString());

        if ($value === '' || ! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * Whether each product has stock this account can order, keyed by product id
     * (P4-1). Read through {@see StockAvailability}, the only place availability
     * is worked out: the product itself when it has no variations, otherwise its
     * active variations, counting this account's own allocation and nobody
     * else's. Only the yes-or-no leaves here — never a quantity, a warehouse or an
     * allocation.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, bool>
     */
    protected function stockStates(Collection $products, BusinessAccount $account): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $variants = ProductVariant::query()
            ->whereIn('product_id', $products->pluck('id')->all())
            ->get(['id', 'product_id', 'sku', 'is_active'])
            ->groupBy('product_id');

        $units = [];
        $skusByProduct = [];

        foreach ($products as $product) {
            /** @var Collection<int, ProductVariant> $own */
            $own = $variants->get($product->id, collect());

            $stockable = $own->isEmpty()
                ? [['sku' => $product->sku, 'product_id' => $product->id, 'variant_id' => null]]
                : $own->where('is_active', true)
                    ->map(fn (ProductVariant $variant) => ['sku' => $variant->sku, 'product_id' => $product->id, 'variant_id' => $variant->id])
                    ->values()
                    ->all();

            $skusByProduct[$product->id] = array_column($stockable, 'sku');
            array_push($units, ...$stockable);
        }

        $answers = $this->stock->forUnits($units, $account);
        $states = [];

        foreach ($skusByProduct as $productId => $skus) {
            $states[$productId] = collect($skus)->contains(fn (string $sku) => ($answers[$sku]['quantity'] ?? 0) > 0);
        }

        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    protected function card(Product $product, SalesChannel $channel, ?bool $inStock = null): array
    {
        $image = $product->media->first();

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category->name,
            'brand' => $product->brand?->name,
            'is_featured' => $product->is_featured,
            'image' => $image === null ? null : [
                'url' => $this->media->url($image->path),
                'alt' => $image->alt_text,
            ],
            ...($channel === SalesChannel::Wholesale
                ? [
                    'wholesale_price' => $product->wholesale_price_minor->jsonSerialize(),
                    'min_order_quantity' => $product->min_order_quantity,
                    'has_quantity_pricing' => (bool) $product->price_tiers_exists,
                    'in_stock' => $inStock ?? false,
                ]
                : $this->sellingGuidance($product)),
        ];
    }

    /**
     * What a dropshipping partner is guided to sell at (§15.1).
     *
     * @return array<string, mixed>
     */
    protected function sellingGuidance(Product $product): array
    {
        return [
            'suggested_selling_price' => $product->suggested_selling_price_minor?->jsonSerialize(),
            'minimum_selling_price' => $product->minimum_selling_price_minor?->jsonSerialize(),
            'maximum_selling_price' => $product->maximum_selling_price_minor?->jsonSerialize(),
        ];
    }
}
