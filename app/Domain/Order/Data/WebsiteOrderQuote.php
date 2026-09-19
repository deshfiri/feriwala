<?php

namespace App\Domain\Order\Data;

use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Data\TaxCharge;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * What a website order costs, worked out by the ERP (contract §6.1, §6.1.1).
 *
 * The authoritative figures a storefront's claim is held to — to the poisha —
 * and the ones the order, its payment and its invoice are written from. Only
 * selling-side figures: no cost, no wholesale price, no margin (D12).
 */
readonly class WebsiteOrderQuote
{
    /**
     * @param  array<int, WebsiteOrderLine>  $lines  in the order the storefront sent them
     */
    public function __construct(
        public array $lines,
        public Currency $currency,
        public Money $subtotal,
        public Money $discount,
        public Money $delivery,
        public TaxCharge $deliveryTax,
        public TaxBreakdown $tax,
        public Money $total,
    ) {}

    /**
     * Whether the storefront's claim is this quote, exactly.
     *
     * @param  array<int, Money>  $unitPrices  claimed, by line position
     * @param  array{subtotal: Money, discount: Money, shipping: Money, tax: Money, grand_total: Money}  $totals
     */
    public function matches(array $unitPrices, array $totals): bool
    {
        if (count($unitPrices) !== count($this->lines)) {
            return false;
        }

        foreach ($this->lines as $position => $line) {
            if (! isset($unitPrices[$position]) || ! $this->same($unitPrices[$position], $line->unitPrice)) {
                return false;
            }
        }

        return $this->same($totals['subtotal'], $this->subtotal)
            && $this->same($totals['discount'], $this->discount)
            && $this->same($totals['shipping'], $this->delivery)
            && $this->same($totals['tax'], $this->tax->addedTotal())
            && $this->same($totals['grand_total'], $this->total);
    }

    /**
     * The figures the storefront should show the customer again (contract §6.1.1).
     *
     * @return array<string, mixed>
     */
    public function authoritative(): array
    {
        return [
            'items' => array_map(fn (WebsiteOrderLine $line) => [
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice->jsonSerialize(),
                'line_total' => $line->total()->jsonSerialize(),
            ], $this->lines),
            'subtotal' => $this->subtotal->jsonSerialize(),
            'discount' => $this->discount->jsonSerialize(),
            'tax' => $this->tax->addedTotal()->jsonSerialize(),
            'shipping' => $this->delivery->jsonSerialize(),
            'grand_total' => $this->total->jsonSerialize(),
        ];
    }

    /** Equal amounts in the same currency; one poisha is a difference. */
    protected function same(Money $claimed, Money $actual): bool
    {
        return $claimed->currency === $actual->currency && $claimed->minorUnits === $actual->minorUnits;
    }
}
