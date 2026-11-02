<?php

namespace App\Http\Controllers\Api\Mobile\V1\Catalog;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Catalog\PurchasedProducts;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\ContentLibrary\ContentBlocks;
use App\Domain\ContentLibrary\Models\ContentLibraryItem;
use App\Domain\Inventory\Queries\PartnerStockSummary;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Inventory\StockEnforcement;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Catalog\CatalogBrowseRequest;
use App\Http\Resources\Mobile\Catalog\CatalogProductCardResource;
use App\Http\Resources\Mobile\Catalog\CatalogProductDetailResource;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The central catalogue, from a business account's side (§10, §12, §13).
 *
 * **Read-only, and one list per channel.** A partner selects from the central
 * catalogue and never writes a product (§12) — there is no create, update or
 * delete here. Dropshipping and wholesale are browsed through separate
 * eligibility queries, so a product switched on only for wholesale cannot be
 * opened on the dropshipping route by typing its identifier. A product the
 * account may not see is a `404`, never a `403`.
 *
 * What each channel shows differs on purpose: wholesale shows what the account
 * pays; dropshipping shows what the partner is guided to sell at. Neither ever
 * shows the base cost.
 */
class CatalogController extends MobileController
{
    use ResolvesBusinessAccount;

    public const PER_PAGE = 24;

    public function __construct(
        protected ProductEligibility $eligibility,
        protected PurchasedProducts $purchased,
        protected PartnerStockSummary $ownership,
        protected ProductMediaStore $media,
        protected WholesalePriceResolver $prices,
        protected StockAvailability $stock,
        protected ManagedStorage $storage,
        protected StockEnforcement $enforcement,
    ) {}

    /**
     * Products
     *
     * Every product this account may use, once, with the ways it may use it
     * (wholesale purchase, Partner Selling) and the server's own prices. Newest
     * first, featured products ahead of the rest, 24 to a page. `facility_allowed`
     * is `false` when the package includes neither channel; the list is then
     * empty. Pass `scope=mine` for only what the account has bought.
     *
     * @response array{data: list<CatalogProductCardResource>, links: array<string, string|null>, meta: array<string, mixed>, facility_allowed: bool, filters: array<string, string|null>, can: array{wholesale: bool, filter_wholesale: bool}, options: array{categories: list<array{value: string, label: string}>, brands: list<array{value: string, label: string}>}}
     */
    public function index(CatalogBrowseRequest $request): AnonymousResourceCollection
    {
        return $this->products($request, $request->input('scope') === 'mine');
    }

    /**
     * My products
     *
     * What this account has bought, with how many of each it owns and where each
     * is on sale (`ownership`). Same filters and pagination as the catalogue.
     *
     * @response array{data: list<CatalogProductCardResource>, links: array<string, string|null>, meta: array<string, mixed>, facility_allowed: bool, filters: array<string, string|null>, can: array{wholesale: bool, filter_wholesale: bool}, options: array{categories: list<array{value: string, label: string}>, brands: list<array{value: string, label: string}>}}
     */
    public function mine(CatalogBrowseRequest $request): AnonymousResourceCollection
    {
        return $this->products($request, true);
    }

    /**
     * Wholesale products
     *
     * The products this account may buy wholesale, with the wholesale price,
     * quantity bands and whether it can order them now. `facility_allowed` is
     * `false`, and the list empty, when the package does not include wholesale.
     *
     * @response array{data: list<CatalogProductCardResource>, links: array<string, string|null>, meta: array<string, mixed>, channel: string, facility_allowed: bool, filters: array<string, string|null>, options: array{categories: list<array{value: string, label: string}>, brands: list<array{value: string, label: string}>}}
     */
    public function wholesale(CatalogBrowseRequest $request): AnonymousResourceCollection
    {
        return $this->browse($request, SalesChannel::Wholesale);
    }

    /**
     * Partner Selling products
     *
     * The products a Partner Selling (dropshipping) account may sell, with the
     * suggested, minimum and maximum selling prices. The wholesale filters do not
     * apply here and are ignored.
     *
     * @response array{data: list<CatalogProductCardResource>, links: array<string, string|null>, meta: array<string, mixed>, channel: string, facility_allowed: bool, filters: array<string, string|null>, options: array{categories: list<array{value: string, label: string}>, brands: list<array{value: string, label: string}>}}
     */
    public function dropshipping(CatalogBrowseRequest $request): AnonymousResourceCollection
    {
        return $this->browse($request, SalesChannel::Dropshipping);
    }

    /**
     * Product detail
     *
     * One product with everything this account may know about it across both
     * ways of using it: description, media, variations, wholesale terms and
     * quantity bands, selling guidance, the account's own stock figure, the
     * storefronts it could be put on, published updates and related products.
     * A product the account may not see answers `404`.
     */
    public function show(CatalogBrowseRequest $request, string $product): CatalogProductDetailResource
    {
        return $this->detail($request, null, $product);
    }

    /**
     * Wholesale product detail
     *
     * The same detail, restricted to what this account may buy wholesale. A
     * product that is not on the wholesale channel for this account answers `404`.
     */
    public function showWholesale(CatalogBrowseRequest $request, string $product): CatalogProductDetailResource
    {
        return $this->detail($request, SalesChannel::Wholesale, $product);
    }

    /**
     * Partner Selling product detail
     *
     * The same detail, restricted to what this account may sell as a Partner. A
     * product that is not on the dropshipping channel for this account answers `404`.
     */
    public function showDropshipping(CatalogBrowseRequest $request, string $product): CatalogProductDetailResource
    {
        return $this->detail($request, SalesChannel::Dropshipping, $product);
    }

    protected function products(CatalogBrowseRequest $request, bool $mine): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);
        $channels = $this->usableChannels($account);
        $wholesaleAllowed = in_array(SalesChannel::Wholesale, $channels, true);

        $query = $this->eligibility->query($account)
            ->where(function (Builder $inner) use ($channels) {
                foreach ($channels as $channel) {
                    $inner->orWhere($channel->column(), $channel->enabled());
                }
            });

        if ($channels === []) {
            $query->whereRaw('1 = 0');
        }

        if ($mine) {
            $this->purchased->only($query, $account);
        }

        [$products, $filters] = $this->page($request, $account, $query, $wholesaleAllowed);

        $stockStates = $wholesaleAllowed ? $this->stockStates($products->getCollection(), $account) : [];
        $bought = $this->purchased->among($account, $products->getCollection()->pluck('id'));
        $owned = $mine ? $this->ownership->forProducts($account, $products->getCollection()->pluck('id')) : [];

        $products->getCollection()->each(fn (Product $product) => $this->attachCard(
            $product,
            $channels,
            $stockStates[$product->id] ?? null,
            isset($bought[$product->id]),
            $owned[$product->id] ?? null,
        ));

        return CatalogProductCardResource::collection($products)->additional([
            'facility_allowed' => $channels !== [],
            'filters' => [...$filters, 'scope' => $mine ? 'mine' : 'all'],
            'can' => ['wholesale' => $wholesaleAllowed, 'filter_wholesale' => $wholesaleAllowed],
            'options' => $this->options(),
        ]);
    }

    protected function browse(CatalogBrowseRequest $request, SalesChannel $channel): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);
        $wholesale = $channel === SalesChannel::Wholesale;

        [$products, $filters] = $this->page($request, $account, $this->eligibility->query($account, $channel), $wholesale);

        $stockStates = $wholesale ? $this->stockStates($products->getCollection(), $account) : [];

        $products->getCollection()->each(fn (Product $product) => $this->attachCard(
            $product,
            [$channel],
            $stockStates[$product->id] ?? null,
        ));

        return CatalogProductCardResource::collection($products)->additional([
            'channel' => $channel->value,
            'facility_allowed' => $this->eligibility->allowsChannel($account, $channel),
            'filters' => $filters,
            'options' => $this->options(),
        ]);
    }

    /**
     * Filter, order and page a query of eligible products.
     *
     * @param  EloquentBuilder<Product>  $query
     * @return array{0: \Illuminate\Pagination\LengthAwarePaginator<int, Product>, 1: array<string, string|null>}
     */
    protected function page(CatalogBrowseRequest $request, BusinessAccount $account, EloquentBuilder $query, bool $wholesaleFilters): array
    {
        $search = trim($request->string('search')->toString());
        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $category = $request->filled('category')
            ? Category::query()->with('children')->where('public_id', $request->string('category')->toString())->first()
            : null;
        $brand = $request->filled('brand')
            ? Brand::query()->where('public_id', $request->string('brand')->toString())->first()
            : null;

        $stock = $wholesaleFilters && $request->filled('stock') ? $request->string('stock')->toString() : null;
        $priceMin = $wholesaleFilters ? $this->priceFilter($request, 'price_min') : null;
        $priceMax = $wholesaleFilters ? $this->priceFilter($request, 'price_max') : null;

        if ($stock !== null) {
            $query = $this->stock->whereProductStock($query, $account, $stock === 'in_stock');
        }

        $products = $query
            ->with([
                'category:id,name',
                'brand:id,name',
                'media' => fn ($media) => $media->where('type', ProductMedia::TYPE_IMAGE),
            ])
            ->withExists('priceTiers')
            ->withCount('variants')
            ->when($search !== '', fn (EloquentBuilder $builder) => $builder->where(
                fn (EloquentBuilder $inner) => $inner->where('name', 'ilike', $pattern)->orWhere('sku', 'ilike', $pattern),
            ))
            // A parent category means its subcategories too.
            ->when($category !== null, fn (EloquentBuilder $builder) => $builder->whereIn('category_id', $category?->descendantIds() ?? []))
            ->when($brand !== null, fn (EloquentBuilder $builder) => $builder->where('brand_id', $brand?->id))
            ->when($priceMin !== null, fn (EloquentBuilder $builder) => $builder->where('wholesale_price', '>=', $priceMin))
            ->when($priceMax !== null, fn (EloquentBuilder $builder) => $builder->where('wholesale_price', '<=', $priceMax))
            ->orderByDesc('is_featured')
            ->orderByDesc('featured_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [$products, [
            'search' => $search,
            'category' => $category?->public_id,
            'brand' => $brand?->public_id,
            'stock' => $stock,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ]];
    }

    /**
     * @return array{categories: list<array{value: string, label: string}>, brands: list<array{value: string, label: string}>}
     */
    protected function options(): array
    {
        return [
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
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, SalesChannel>  $channels
     * @param  array<string, mixed>|null  $ownership
     */
    protected function attachCard(Product $product, array $channels, ?bool $inStock, bool $purchased = false, ?array $ownership = null): void
    {
        $wholesale = in_array(SalesChannel::Wholesale, $channels, true) && $product->sellsThrough(SalesChannel::Wholesale);
        $partnerSelling = in_array(SalesChannel::Dropshipping, $channels, true) && $product->sellsThrough(SalesChannel::Dropshipping);
        $image = $product->media->first();

        $product->setAttribute('catalog_card', [
            'wholesale' => $wholesale,
            'partner_selling' => $partnerSelling,
            'in_stock' => $inStock,
            'purchased' => $purchased,
            'ownership' => $ownership,
            'tiers' => $wholesale ? $this->quantityPricing($product, null) : [],
            'image_url' => $image === null ? null : $this->media->url($image->path, $image->disk),
        ]);
    }

    protected function detail(CatalogBrowseRequest $request, ?SalesChannel $channel, string $slug): CatalogProductDetailResource
    {
        $account = $this->businessAccountFor($request);
        $channels = $this->usableChannels($account);

        /** @var Product|null $product */
        $product = $this->visibleQuery($account, $channel)
            ->where('slug', $slug)
            ->with(['category.parent', 'brand', 'media', 'variants.values.attribute', 'variants.product'])
            ->with(['contents' => fn ($query) => $query->with('attachment')])
            ->with('libraryContent')
            ->first();

        abort_if($product === null, 404);

        $wholesale = $channel === null
            ? in_array(SalesChannel::Wholesale, $channels, true) && $product->sellsThrough(SalesChannel::Wholesale)
            : $channel === SalesChannel::Wholesale;
        $partnerSelling = $channel === null
            ? in_array(SalesChannel::Dropshipping, $channels, true) && $product->sellsThrough(SalesChannel::Dropshipping)
            : $channel === SalesChannel::Dropshipping;

        $variantTiers = [];

        if ($wholesale) {
            foreach ($product->variants->where('is_active', true) as $variant) {
                $variantTiers[$variant->sku] = $this->quantityPricing($product, $variant);
            }
        }

        // Related products the account may itself see, in the recommended order.
        $relatedIds = $product->relatedProducts()->pluck('products.id')->all();
        $positions = array_flip($relatedIds);

        /** @var Collection<int, Product> $related */
        $related = $relatedIds === []
            ? collect()
            : $this->visibleQuery($account, $channel)
                ->whereIn('id', $relatedIds)
                ->with(['category:id,name', 'brand:id,name', 'media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE)])
                ->withExists('priceTiers')
                ->withCount('variants')
                ->get()
                ->sortBy(fn (Product $item) => $positions[$item->id] ?? PHP_INT_MAX)
                ->values();

        $relatedStock = in_array(SalesChannel::Wholesale, $channels, true) ? $this->stockStates($related, $account) : [];
        $related->each(fn (Product $item) => $this->attachCard($item, $channels, $relatedStock[$item->id] ?? null));

        $canPublish = $this->purchased->maySell($account, $product);

        return new CatalogProductDetailResource($product, [
            'channel' => $channel === null ? 'all' : $channel->value,
            'wholesale' => $wholesale,
            'partner_selling' => $partnerSelling,
            'available' => $wholesale ? $this->orderableUnits($product, $account) : [],
            'tiers' => $wholesale ? $this->quantityPricing($product, null) : [],
            'variant_tiers' => $variantTiers,
            'stock_enforced' => $this->enforcement->enforced(),
            'purchased' => $this->purchased->has($account, $product),
            'can_publish' => $canPublish,
            'websites' => $partnerSelling && $canPublish ? $this->websitesFor($account, $product) : [],
            'media' => $product->media
                ->map(fn (ProductMedia $item) => [
                    'type' => $item->type,
                    'url' => $this->media->url($item->path, $item->disk),
                    'alt' => $item->alt_text,
                ])
                ->values()
                ->all(),
            'content' => $product->contents
                ->map(fn (ProductContent $content) => [
                    'id' => $content->public_id,
                    'title' => $content->title,
                    'body' => $content->body,
                    'published_at' => $content->published_at->toIso8601String(),
                    'attachment' => $content->attachment === null ? null : [
                        'url' => $this->storage->url($content->attachment),
                        'mime_type' => $content->attachment->mime_type,
                        'is_image' => str_starts_with($content->attachment->mime_type, 'image/'),
                    ],
                ])
                ->values()
                ->all(),
            'library_content' => $product->libraryContent
                ->map(fn (ContentLibraryItem $item) => [
                    'id' => $item->public_id,
                    'title' => $item->title,
                    'published_at' => $item->published_at->toIso8601String(),
                    'blocks' => app(ContentBlocks::class)->present($item->blocks),
                ])
                ->values()
                ->all(),
            'related' => $related->all(),
        ]);
    }

    /**
     * The channels this account's package lets it use.
     *
     * @return array<int, SalesChannel>
     */
    protected function usableChannels(BusinessAccount $account): array
    {
        return array_values(array_filter(
            [SalesChannel::Wholesale, SalesChannel::Dropshipping],
            fn (SalesChannel $channel) => $this->eligibility->allowsChannel($account, $channel),
        ));
    }

    /**
     * What the account may see: one channel's products, or every product on any
     * channel its package includes.
     *
     * @return EloquentBuilder<Product>
     */
    protected function visibleQuery(BusinessAccount $account, ?SalesChannel $channel): EloquentBuilder
    {
        if ($channel !== null) {
            return $this->eligibility->query($account, $channel);
        }

        $channels = $this->usableChannels($account);

        return $this->eligibility->query($account)
            ->where(function (Builder $inner) use ($channels) {
                foreach ($channels as $usable) {
                    $inner->orWhere($usable->column(), $usable->enabled());
                }
            })
            ->when($channels === [], fn (EloquentBuilder $query) => $query->whereRaw('1 = 0'));
    }

    /**
     * This account's open storefronts, and whether each already sells the product.
     *
     * @return list<array{id: string, name: string, selected: bool}>
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
     * How many of each stockable unit this account can order, keyed by SKU: the
     * product itself when it has no variations, otherwise its active variations.
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
     * @return list<array{min_quantity: int, unit_price: Money}>
     */
    protected function quantityPricing(Product $product, ?ProductVariant $variant): array
    {
        return $this->prices->tiersFor($product, $variant)
            ->map(fn (ProductPriceTier $tier) => [
                'min_quantity' => $tier->min_quantity,
                'unit_price' => $this->prices->unitPrice($product, $variant, $tier->min_quantity),
            ])
            ->values()
            ->all();
    }

    /**
     * A wholesale price bound typed in Taka, as an exact decimal string, or null.
     */
    protected function priceFilter(CatalogBrowseRequest $request, string $key): ?string
    {
        $value = trim($request->string($key)->toString());

        if ($value === '' || ! preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return Money::fromDecimal($value, Currency::BDT)->toDecimal();
    }

    /**
     * Whether each product has stock this account can order, keyed by product id.
     * Only the yes-or-no leaves here — never a quantity, a warehouse or an
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
}
