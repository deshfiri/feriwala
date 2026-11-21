<?php

namespace App\Domain\Order\Data;

use App\Domain\Billing\Data\DeliveryChargeResolution;
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
        /** Where `$delivery` came from, frozen onto the order; null until a delivery location is known. */
        public ?DeliveryChargeResolution $deliveryCharge = null,
    ) {}

    /**
     * Whether the delivery charge is in these figures. Without a delivery
     * location only the goods are priced: `$delivery` is zero and `$total`
     * leaves delivery out, so such a quote is never shown as a grand total
     * and never placed.
     */
    public function isDeliveryPriced(): bool
    {
        return $this->deliveryCharge !== null;
    }

    /**
     * The delivery as a storefront shows it: the area and whether it is free,
     * or that an address is still needed. Never a rule id or other internals.
     *
     * @return array{status: string, area: array{code: string, label: string}|null, charge: array<string, mixed>|null, is_free: bool}
     */
    public function deliverySummary(): array
    {
        $charge = $this->deliveryCharge;

        return [
            'status' => $charge === null ? 'address_required' : 'priced',
            'area' => $charge === null ? null : ['code' => $charge->area->value, 'label' => $charge->area->label()],
            'charge' => $charge?->amount->jsonSerialize(),
            'is_free' => $charge?->isFree() ?? false,
        ];
    }

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
            // No delivery location yet: no delivery charge and no grand total,
            // rather than a total that quietly leaves delivery out (D37).
            'shipping' => $this->isDeliveryPriced() ? $this->delivery->jsonSerialize() : null,
            'grand_total' => $this->isDeliveryPriced() ? $this->total->jsonSerialize() : null,
            'delivery' => $this->deliverySummary(),
        ];
    }

    /** Equal amounts in the same currency; one poisha is a difference. */
    protected function same(Money $claimed, Money $actual): bool
    {
        return $claimed->currency === $actual->currency && $claimed->equals($actual);
    }
}
