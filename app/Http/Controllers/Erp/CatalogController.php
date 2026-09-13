<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
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
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

    public function __construct(
        protected ProductEligibility $eligibility,
        protected ProductMediaStore $media,
        protected WholesalePriceResolver $prices,
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

        $products = $this->eligibility->query($account, $channel)
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
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product) => $this->card($product, $channel));

        return Inertia::render('catalog/browse', [
            'channel' => $channel->value,
            'facility_allowed' => $this->eligibility->allowsChannel($account, $channel),
            'products' => $products,
            'filters' => [
                'search' => $search,
                'category' => $category?->public_id,
                'brand' => $brand?->public_id,
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
                    ...($channel === SalesChannel::Wholesale
                        ? ['wholesale_price' => $variant->effectiveWholesalePrice()->jsonSerialize()]
                        : []),
                ])
                ->values()
                ->all(),
        ];

        if ($channel === SalesChannel::Wholesale) {
            $detail += [
                'wholesale_price' => $product->wholesale_price_minor->jsonSerialize(),
                'min_order_quantity' => $product->min_order_quantity,
                'max_order_quantity' => $product->max_order_quantity,

                // Resolved by the server at each band's starting quantity, so
                // a band the base price has since undercut shows what is
                // actually charged.
                'quantity_pricing' => $this->prices->tiersFor($product, null)
                    ->map(fn (ProductPriceTier $tier) => [
                        'min_quantity' => $tier->min_quantity,
                        'unit_price' => $this->prices->unitPrice($product, null, $tier->min_quantity)->jsonSerialize(),
                    ])
                    ->values()
                    ->all(),
            ];
        } else {
            $detail += $this->sellingGuidance($product);
        }

        return Inertia::render('catalog/show', [
            'channel' => $channel->value,
            'product' => $detail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function card(Product $product, SalesChannel $channel): array
    {
        $image = $product->media->first();

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category->name,
            'brand' => $product->brand?->name,
            'image' => $image === null ? null : [
                'url' => $this->media->url($image->path),
                'alt' => $image->alt_text,
            ],
            ...($channel === SalesChannel::Wholesale
                ? [
                    'wholesale_price' => $product->wholesale_price_minor->jsonSerialize(),
                    'min_order_quantity' => $product->min_order_quantity,
                    'has_quantity_pricing' => (bool) $product->price_tiers_exists,
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
