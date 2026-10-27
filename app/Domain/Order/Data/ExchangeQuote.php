<?php

namespace App\Domain\Order\Data;

use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * What an exchange costs and refunds, worked out by the ERP (D35).
 *
 * Shown to the Partner before they confirm, and written once onto the case when
 * they do. **Nothing here is a figure the client sent**: the lines, the price
 * difference and the delivery charge are all the server's own.
 *
 * The price difference is exact and in one direction: the customer owes
 * `additionalDue` for a dearer replacement, or is owed `refundDue` for a cheaper
 * one, and never both. The one new delivery an exchange sends is `delivery`.
 */
readonly class ExchangeQuote
{
    /**
     * @param  array<int, ExchangeLine>  $lines
     * @param  array<string, mixed>|null  $deliveryCalculation  the delivery charge's own breakdown, frozen with it
     */
    public function __construct(
        public array $lines,
        public Currency $currency,
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public Money $taxIncluded,
        public Money $total,
        public Money $additionalDue,
        public Money $refundDue,
        public Money $delivery,
        public ?array $deliveryCalculation = null,
    ) {}

    /**
     * Whether the replacement costs the customer or Banij anything beyond the
     * goods already sold: an exchange of the same unit has no difference.
     */
    public function hasDifference(): bool
    {
        return $this->additionalDue->isPositive() || $this->refundDue->isPositive();
    }

    /**
     * What a screen shows before the Partner confirms.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return [
            'lines' => array_map(fn (ExchangeLine $line) => [
                'replaces_sku' => $line->replaces->sku,
                'sku' => $line->sku,
                'name' => $line->product->name,
                'quantity' => $line->quantity,
                'same_product' => $line->sameProduct,
                'unit_price' => $line->unitPrice->jsonSerialize(),
                'difference' => $line->difference()->jsonSerialize(),
            ], $this->lines),
            'additional_due' => $this->additionalDue->jsonSerialize(),
            'refund_due' => $this->refundDue->jsonSerialize(),
            'delivery' => $this->delivery->jsonSerialize(),
        ];
    }
}
