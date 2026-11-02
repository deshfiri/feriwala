<?php

namespace App\Http\Resources\Mobile\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\ContentLibrary\Models\ContentLibraryItem;
use App\Http\Resources\Mobile\MoneyResource;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product with everything this account may know about it (§10, §13, §15.1).
 *
 * The figures are the server's own and are already resolved for this account:
 * stock is only ever what this account can order, and the base cost is never
 * shown. `context` is worked out by the controller and carries nothing a client
 * sent.
 *
 * @property Product $resource
 */
class CatalogProductDetailResource extends JsonResource
{
    /**
     * @param  array{channel: string, wholesale: bool, partner_selling: bool, available: array<string, int>, tiers: list<array{min_quantity: int, unit_price: Money}>, variant_tiers: array<string, list<array{min_quantity: int, unit_price: Money}>>, stock_enforced: bool, purchased: bool, can_publish: bool, websites: list<array{id: string, name: string, selected: bool}>, media: list<array{type: string, url: string, alt: string|null}>, content: list<array{id: string, title: string, body: string|null, published_at: string, attachment: array{url: string, mime_type: string, is_image: bool}|null}>, library_content: list<array{id: string, title: string, published_at: string, blocks: mixed}>, related: list<Product>}  $context
     */
    public function __construct(Product $resource, protected array $context)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $context = $this->context;
        $wholesale = $context['wholesale'];
        $hasVariants = $product->variants->isNotEmpty();

        return [
            /** The identifier used in the catalogue URLs. */
            'slug' => $product->slug,
            /** The product's public identifier. */
            'public_id' => $product->public_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'category' => $product->category->parent === null
                ? $product->category->name
                : $product->category->parent->name.' › '.$product->category->name,
            'brand' => $product->brand?->name,
            'package_scope' => $product->package_scope->value,
            /** `wholesale`, `dropshipping`, or `all` when opened from the unified catalogue. */
            'channel' => $context['channel'],
            'modes' => ['wholesale' => $wholesale, 'partner_selling' => $context['partner_selling']],
            'media' => $context['media'],
            /** Active variations only. Wholesale prices and stock appear only when this account may buy wholesale. */
            'variants' => $product->variants
                ->where('is_active', true)
                ->map(fn (ProductVariant $variant) => [
                    'label' => $variant->values
                        ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                        ->pluck('value')
                        ->implode(' / '),
                    'sku' => $variant->sku,
                    'wholesale_price' => $wholesale ? new MoneyResource($variant->effectiveWholesalePrice()) : null,
                    'in_stock' => $wholesale ? ($context['available'][$variant->sku] ?? 0) > 0 : null,
                    'available' => $wholesale ? ($context['available'][$variant->sku] ?? 0) : null,
                    'quantity_pricing' => $wholesale ? $this->bands($context['variant_tiers'][$variant->sku] ?? []) : [],
                ])
                ->values()
                ->all(),
            /** Wholesale terms, or `null` when this account may not buy the product wholesale. */
            'wholesale' => $wholesale ? [
                'price' => new MoneyResource($product->wholesale_price),
                'min_order_quantity' => $product->min_order_quantity,
                'max_order_quantity' => $product->max_order_quantity,
                'quantity_pricing' => $this->bands($context['tiers']),
                'in_stock' => collect($context['available'])->contains(fn (int $units) => $units > 0),
                /** Units this account can order now; `null` when the product has variations (read each variation). */
                'available' => $hasVariants ? null : ($context['available'][$product->sku] ?? 0),
            ] : null,
            /** The selling guidance, or `null` when this account may not use Partner Selling on it. */
            'partner_selling' => $context['partner_selling'] ? [
                'suggested_selling_price' => MoneyResource::nullable($product->suggested_selling_price),
                'minimum_selling_price' => MoneyResource::nullable($product->minimum_selling_price),
                'maximum_selling_price' => MoneyResource::nullable($product->maximum_selling_price),
            ] : null,
            'stock_enforced' => $context['stock_enforced'],
            'purchased' => $context['purchased'],
            'can_publish' => $context['can_publish'],
            /** The partner's open storefronts and whether each already sells this. Empty unless it can be published. */
            'websites' => $context['websites'],
            /** Updates published for this product, newest first. */
            'content' => $context['content'],
            /** Content Library items released to this product, as structured blocks (never HTML). */
            'library_content' => $context['library_content'],
            /** Other products this account may also see, in the recommended order. */
            'related' => CatalogProductCardResource::collection(collect($context['related'])),
        ];
    }

    /**
     * @param  list<array{min_quantity: int, unit_price: Money}>  $bands
     * @return list<array{min_quantity: int, unit_price: MoneyResource}>
     */
    protected function bands(array $bands): array
    {
        return array_map(fn (array $band) => [
            'min_quantity' => $band['min_quantity'],
            'unit_price' => new MoneyResource($band['unit_price']),
        ], $bands);
    }
}
