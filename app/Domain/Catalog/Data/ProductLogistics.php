<?php

namespace App\Domain\Catalog\Data;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * The logistics figures that actually apply to a sellable unit (beta-critical
 * batch, Commit 1).
 *
 * A variant's own column wins when it is set; the product's own figure is
 * used otherwise -- the same per-field fallback `ProductVariant::
 * effectiveWholesalePrice()` already applies to one field, generalised here
 * across every logistics column so a variant can override just its own
 * weight while leaving every other figure inherited.
 *
 * Every weight is whole grams; every dimension is a decimal string of
 * centimetres (never a float, per D26's own "no float money" rule extended
 * here to physical measurements) or null when not yet recorded.
 */
class ProductLogistics
{
    public function __construct(
        public readonly ?int $netWeightGrams,
        public readonly ?int $shippingWeightGrams,
        public readonly ?string $lengthCm,
        public readonly ?string $widthCm,
        public readonly ?string $heightCm,
        public readonly bool $shipsByBox,
        public readonly ?int $piecesPerBox,
        public readonly ?int $boxWeightGrams,
        public readonly ?string $boxLengthCm,
        public readonly ?string $boxWidthCm,
        public readonly ?string $boxHeightCm,
        public readonly bool $isFragile,
    ) {}

    public static function forProduct(Product $product): self
    {
        return new self(
            netWeightGrams: $product->net_weight_grams,
            shippingWeightGrams: $product->shipping_weight_grams,
            lengthCm: $product->length_cm,
            widthCm: $product->width_cm,
            heightCm: $product->height_cm,
            shipsByBox: $product->ships_by_box,
            piecesPerBox: $product->pieces_per_box,
            boxWeightGrams: $product->box_weight_grams,
            boxLengthCm: $product->box_length_cm,
            boxWidthCm: $product->box_width_cm,
            boxHeightCm: $product->box_height_cm,
            isFragile: $product->is_fragile,
        );
    }

    public static function forVariant(ProductVariant $variant): self
    {
        $product = $variant->product;

        return new self(
            netWeightGrams: $variant->net_weight_grams ?? $product->net_weight_grams,
            shippingWeightGrams: $variant->shipping_weight_grams ?? $product->shipping_weight_grams,
            lengthCm: $variant->length_cm ?? $product->length_cm,
            widthCm: $variant->width_cm ?? $product->width_cm,
            heightCm: $variant->height_cm ?? $product->height_cm,
            shipsByBox: $variant->ships_by_box ?? $product->ships_by_box,
            piecesPerBox: $variant->pieces_per_box ?? $product->pieces_per_box,
            boxWeightGrams: $variant->box_weight_grams ?? $product->box_weight_grams,
            boxLengthCm: $variant->box_length_cm ?? $product->box_length_cm,
            boxWidthCm: $variant->box_width_cm ?? $product->box_width_cm,
            boxHeightCm: $variant->box_height_cm ?? $product->box_height_cm,
            isFragile: $variant->is_fragile ?? $product->is_fragile,
        );
    }

    /**
     * The weight one unit actually ships at: the shipping figure when
     * recorded (packaging adds to the net figure), else the net weight, else
     * unknown.
     */
    public function unitWeightGrams(): ?int
    {
        return $this->shippingWeightGrams ?? $this->netWeightGrams;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'net_weight_grams' => $this->netWeightGrams,
            'shipping_weight_grams' => $this->shippingWeightGrams,
            'length_cm' => $this->lengthCm,
            'width_cm' => $this->widthCm,
            'height_cm' => $this->heightCm,
            'ships_by_box' => $this->shipsByBox,
            'pieces_per_box' => $this->piecesPerBox,
            'box_weight_grams' => $this->boxWeightGrams,
            'box_length_cm' => $this->boxLengthCm,
            'box_width_cm' => $this->boxWidthCm,
            'box_height_cm' => $this->boxHeightCm,
            'is_fragile' => $this->isFragile,
        ];
    }
}
