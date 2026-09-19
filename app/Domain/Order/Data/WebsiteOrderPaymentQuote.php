<?php

namespace App\Domain\Order\Data;

use App\Domain\Billing\Data\PaymentQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/**
 * A website order as the payment it becomes (§9, §17, P5-23).
 *
 * The components a payment and its invoice carry: one goods component per line,
 * then the delivery charge and the tax added on top — with the tax per rate
 * beside them. Its total is the order's, to the poisha.
 */
readonly class WebsiteOrderPaymentQuote implements PaymentQuote
{
    /**
     * @param  array<int, QuoteLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Currency $currency,
        public TaxBreakdown $tax,
    ) {}

    public static function fromQuote(WebsiteOrderQuote $quote): self
    {
        $lines = [];

        foreach ($quote->lines as $line) {
            $lines[] = new QuoteLine(
                AllocationType::WebsiteGoods,
                $line->subtotal,
                sprintf('%s (%s) × %d', Str::limit($line->product->name, 120), $line->sku, $line->quantity),
            );
        }

        if ($quote->discount->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::Discount, $quote->discount);
        }

        if ($quote->delivery->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::DeliveryCharge, $quote->delivery);
        }

        $added = $quote->tax->addedTotal();

        if ($added->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::Tax, $added);
        }

        return new self($lines, $quote->currency, $quote->tax);
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
     * Goods and delivery, less any discount. Tax is collected on someone else's
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
