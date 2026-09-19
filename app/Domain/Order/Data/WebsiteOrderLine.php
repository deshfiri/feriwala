<?php

namespace App\Domain\Order\Data;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Tax\Data\TaxCharge;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Money;

/**
 * One line of a website order, as the ERP prices it (contract §6.1.1, P5-23).
 *
 * The website's own selling price for the product — the promotion where there
 * is one — times the quantity, taxed at the rule in force for the product. The
 * storefront's claim is compared against this, never the other way round.
 */
readonly class WebsiteOrderLine
{
    public function __construct(
        public WebsiteProduct $selection,
        public Product $product,
        public ?ProductVariant $variant,
        public string $sku,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $discount,
        public TaxCharge $tax,
    ) {}

    /** Tax added on top of the price; nothing when the price already includes it. */
    public function addedTax(): Money
    {
        return $this->tax->mode->isInclusive() ? Money::zero($this->subtotal->currency) : $this->tax->tax;
    }

    /** Tax already inside the price: shown, never added. */
    public function includedTax(): Money
    {
        return $this->tax->mode->isInclusive() ? $this->tax->tax : Money::zero($this->subtotal->currency);
    }

    public function total(): Money
    {
        return $this->subtotal->minus($this->discount)->plus($this->addedTax());
    }
}
