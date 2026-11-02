<?php

namespace App\Http\Resources\Mobile\Wholesale;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cart line as the server prices it now (P4-4, P4-5).
 *
 * @property CartLineQuote $resource
 */
class CartLineResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $line = $this->resource;
        $item = $line->item;
        $product = $item->product;
        $variant = $item->variant;
        $image = $product->media()->where('type', ProductMedia::TYPE_IMAGE)->first();

        return [
            /** The line's public id, used to change or remove it. */
            'id' => $item->public_id,
            'product' => [
                'slug' => $product->slug,
                'name' => $product->name,
                'sku' => $product->sku,
                'image' => $image === null ? null : [
                    'url' => app(ProductMediaStore::class)->url($image->path, $image->disk),
                    'alt' => $image->alt_text,
                ],
            ],
            'variant' => $variant === null ? null : [
                'sku' => $variant->sku,
                'label' => $variant->values
                    ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                    ->pluck('value')
                    ->implode(' / '),
            ],
            'quantity' => $item->quantity,
            'min_order_quantity' => $product->min_order_quantity,
            'max_order_quantity' => $product->max_order_quantity,
            /** Units this account can order now. */
            'available' => $line->available,
            /** Price per unit at this quantity. `null` when the product is no longer available to the account. */
            'unit_price' => MoneyResource::nullable($line->unitPrice),
            'base_price' => MoneyResource::nullable($line->basePrice),
            'line_total' => MoneyResource::nullable($line->lineTotal),
            /** The unit price the person last saw or accepted. */
            'unit_price_seen' => MoneyResource::nullable($line->unitPriceSeen),
            'price_changed' => $line->priceChanged,
            /** Why the line cannot be bought: `unavailable`, `choose_variation`, `variation_unavailable`, `below_minimum`, `above_maximum`, `insufficient_stock`. */
            'problems' => $line->problems,
            'purchasable' => $line->isPurchasable(),
        ];
    }
}
