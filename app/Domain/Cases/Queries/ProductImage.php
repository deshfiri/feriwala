<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Order\Models\OrderItem;

/**
 * The picture shown beside a product on a case (D35).
 *
 * An order line carries no picture of its own — what was bought is recorded as
 * text — so this looks up the product's own first image, if it still exists and
 * has one. It is the catalogue's public image, never a case file; absence is
 * normal and the screen shows a plain tile instead.
 */
class ProductImage
{
    public function __construct(
        protected ProductMediaStore $media,
    ) {}

    /**
     * @return array{url: string, alt: string|null}|null
     */
    public function for(OrderItem $item): ?array
    {
        $product = $item->product;

        if ($product === null) {
            return null;
        }

        /** @var ProductMedia|null $image */
        $image = $product->media()->where('type', ProductMedia::TYPE_IMAGE)->first();

        return $image === null ? null : ['url' => $this->media->url($image->path), 'alt' => $image->alt_text];
    }
}
