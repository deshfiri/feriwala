<?php

namespace App\Domain\Wholesale\Data;

use App\Domain\Billing\Data\PaymentQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * A confirmed wholesale checkout as the payment it becomes (§14, §9, P4-9).
 *
 * The components a payment and its invoice carry, taken from the checkout the
 * server priced and the person confirmed: one goods component per order line,
 * then the discount, the delivery charge and the tax added on top — with the tax
 * per rate beside them. Its total is the checkout's total, to the poisha.
 */
readonly class WholesalePaymentQuote implements PaymentQuote
{
    /**
     * @param  array<int, QuoteLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Currency $currency,
        public TaxBreakdown $tax,
    ) {}

    public static function fromCheckout(CheckoutQuote $checkout): self
    {
        $currency = $checkout->total->currency;
        $lines = [];

        foreach ($checkout->lineCharges as $charge) {
            $item = $charge->line->item;
            $sku = $item->variant !== null ? $item->variant->sku : $item->product->sku;

            $lines[] = new QuoteLine(
                AllocationType::WholesaleGoods,
                $charge->line->lineTotal ?? Money::zero($currency),
                sprintf('%s (%s) × %d', $item->product->name, $sku, $item->quantity),
            );
        }

        if ($checkout->discount->isPositive()) {
            $code = $checkout->coupon?->coupon?->code;

            $lines[] = new QuoteLine(AllocationType::Discount, $checkout->discount, $code === null ? null : 'Coupon '.$code);
        }

        if ($checkout->delivery->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::DeliveryCharge, $checkout->delivery);
        }

        $added = $checkout->tax->addedTotal();

        if ($added->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::Tax, $added);
        }

        return new self($lines, $currency, $checkout->tax);
    }

    /**
     * @return array<int, QuoteLine>
     */
    public function paymentLines(): array
    {
        return $this->lines;
    }

    public function paymentCurrency(): Currency
    {
        return $this->currency;
    }

    public function taxBreakdown(): TaxBreakdown
    {
        return $this->tax;
    }

    public function total(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            $total = $total->plus($line->signedAmount());
        }

        return $total;
    }

    /**
     * Goods and delivery, less the discount. Tax is collected on someone else's
     * behalf and is not revenue.
     */
    public function revenue(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            if ($line->type->isRevenue()) {
                $total = $total->plus($line->amount);
            }
        }

        foreach ($this->lines as $line) {
            if ($line->type === AllocationType::Discount) {
                $total = $total->minus($line->amount);
            }
        }

        return $total;
    }
}
