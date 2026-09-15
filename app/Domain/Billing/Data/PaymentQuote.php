<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * A priced breakdown a payment can be recorded from (§9, §26.3).
 *
 * What {@see RecordPaymentFromQuote} needs to write one payment
 * with its allocations and tax lines: the components, the currency, the total,
 * the part that is revenue, and the tax per rate. An activation quote and a
 * wholesale order quote are both this, so both go through the one recorder.
 */
interface PaymentQuote
{
    /**
     * The components, in the order they were quoted.
     *
     * @return array<int, QuoteLine>
     */
    public function paymentLines(): array;

    public function paymentCurrency(): Currency;

    /** What is actually paid. */
    public function total(): Money;

    /** The part of the payment that is Feriwala's revenue. */
    public function revenue(): Money;

    /** The tax behind the Tax component, per rate. */
    public function taxBreakdown(): TaxBreakdown;
}
