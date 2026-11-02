<?php

namespace App\Http\Resources\Mobile\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Http\Resources\Mobile\MoneyResource;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product on a catalogue list: what it is, and the ways this account may use
 * it. The controller attaches the per-account context (stock, purchase, terms)
 * as the transient `catalog_card` attribute; none of it is saved.
 *
 * Neither channel ever shows the base cost, and neither shows the other
 * channel's prices (§13).
 *
 * @property Product $resource
 */
class CatalogProductCardResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        /** @var array{wholesale: bool, partner_selling: bool, in_stock: bool|null, purchased: bool, ownership: array<string, mixed>|null, tiers: list<array{min_quantity: int, unit_price: Money}>, image_url: string|null} $context */
        $context = $product->getAttribute('catalog_card');
        $image = $product->media->first();

        return [
            /** The identifier used in the catalogue URLs. */
            'slug' => $product->slug,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category->name,
            'brand' => $product->brand?->name,
            'is_featured' => (bool) $product->is_featured,
            /** Bought before: reordering belongs to My Products. */
            'purchased' => $context['purchased'],
            /** What the account owns of it and where it is on sale. Only on My Products, otherwise `null`. */
            'ownership' => $context['ownership'],
            'variant_count' => (int) $product->variants_count,
            'package_scope' => $product->package_scope->value,
            'image' => $image === null ? null : [
                'url' => $context['image_url'],
                'alt' => $image->alt_text,
            ],
            /** The ways this account may use the product. */
            'modes' => ['wholesale' => $context['wholesale'], 'partner_selling' => $context['partner_selling']],
            /** Wholesale terms, or `null` when the product is not buyable wholesale by this account. */
            'wholesale' => $context['wholesale'] ? [
                'price' => new MoneyResource($product->wholesale_price),
                'min_order_quantity' => $product->min_order_quantity,
                'max_order_quantity' => $product->max_order_quantity,
                'in_stock' => $context['in_stock'] ?? false,
                /** Quantity bands, each priced by the server at its starting quantity. */
                'tiers' => array_map(fn (array $tier) => [
                    'min_quantity' => $tier['min_quantity'],
                    'unit_price' => new MoneyResource($tier['unit_price']),
                ], $context['tiers']),
            ] : null,
            /** What a Partner Selling account is guided to sell at, or `null`. */
            'partner_selling' => $context['partner_selling'] ? [
                'suggested_selling_price' => MoneyResource::nullable($product->suggested_selling_price),
                'minimum_selling_price' => MoneyResource::nullable($product->minimum_selling_price),
                'maximum_selling_price' => MoneyResource::nullable($product->maximum_selling_price),
            ] : null,
        ];
    }
}
