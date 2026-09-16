<?php

namespace App\Domain\Website\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;

/**
 * One published product, as a storefront receives it (contract §5.1, P5-22).
 *
 * **The price is the website's own selling price** — the promotion where there
 * is one, with the regular price as `compare_at_price`. The wholesale price, the
 * base cost and the margin are **never** here, in this payload or any other on
 * this surface (D12): the storefront cannot see what Feriwala paid and cannot
 * work out what the partner earns.
 *
 * Categories are the shop's own placement where it has one, and the
 * catalogue's category otherwise, so a storefront always has somewhere to put a
 * product.
 *
 * A product without variations is sent as one variant carrying the product's
 * own SKU, so a storefront handles every product the same way.
 *
 * Availability is advisory (contract §5.2). The binding check is at order
 * submission; a storefront that trusts this figure will oversell.
 */
class StorefrontProductPayload
{
    public function __construct(
        protected ProductMediaStore $media,
        protected StockAvailability $stock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(WebsiteProduct $selection, Website $website): array
    {
        $product = $selection->product;
        $price = $selection->sellingPrice();
        $compareAt = $selection->promotional_price_minor !== null ? $selection->price_minor : null;

        $availability = collect($this->stock->forProduct($product, $website->businessAccount))->keyBy('sku');

        $variants = $product->variants->where('is_active', true)->values();

        return [
            'id' => $product->public_id,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'name' => $product->name,
            'short_description' => $product->short_description,
            'description' => $selection->marketing_description ?? $product->description,
            'promo_title' => $selection->promo_title,
            'brand' => $product->brand === null ? null : [
                'id' => $product->brand->public_id,
                'name' => $product->brand->name,
                'slug' => $product->brand->slug,
            ],
            'categories' => [
                $selection->websiteCategory !== null
                    ? [
                        'id' => $selection->websiteCategory->public_id,
                        'slug' => $selection->websiteCategory->slug,
                        'name' => $selection->websiteCategory->name,
                    ]
                    : [
                        'id' => $product->category->public_id,
                        'slug' => $product->category->slug,
                        'name' => $product->category->name,
                    ],
            ],
            'media' => $product->media
                ->sortBy('position')
                ->values()
                ->map(fn (ProductMedia $item) => [
                    'url' => $this->media->url($item->path),
                    'alt' => $item->alt_text,
                    'position' => $item->position,
                    'type' => $item->type,
                ])
                ->all(),
            'variants' => $variants->isEmpty()
                ? [$this->variant($product->public_id, $product->sku, [], $price, $compareAt, $availability->get($product->sku))]
                : $variants->map(fn (ProductVariant $variant) => $this->variant(
                    $variant->public_id,
                    $variant->sku,
                    $variant->values
                        ->mapWithKeys(fn (ProductAttributeValue $value) => [$value->attribute->slug => $value->value])
                        ->all(),
                    $price,
                    $compareAt,
                    $availability->get($variant->sku),
                ))->all(),
            'min_order_quantity' => $product->min_order_quantity,
            'max_order_quantity' => $product->max_order_quantity,
            'is_featured' => $selection->is_featured,
            'display_order' => $selection->display_order,
            'seo' => [
                'title' => $product->meta_title ?? $product->name,
                'description' => $product->meta_description ?? $product->short_description,
                'canonical' => 'https://'.$website->host().'/products/'.$product->slug,
            ],
            'published_at' => $selection->published_at?->toIso8601String(),
            'updated_at' => ($selection->updated_at->greaterThan($product->updated_at)
                ? $selection->updated_at
                : $product->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     * @param  array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}|null  $availability
     * @return array<string, mixed>
     */
    protected function variant(
        string $id,
        string $sku,
        array $attributes,
        mixed $price,
        mixed $compareAt,
        ?array $availability,
    ): array {
        return [
            'id' => $id,
            'sku' => $sku,
            'attributes' => (object) $attributes,
            'price' => $price?->jsonSerialize(),
            'compare_at_price' => $compareAt?->jsonSerialize(),
            'availability' => [
                'in_stock' => $availability['in_stock'] ?? false,
                'quantity' => $availability['quantity'] ?? 0,
                'backorderable' => false,
            ],
        ];
    }
}
