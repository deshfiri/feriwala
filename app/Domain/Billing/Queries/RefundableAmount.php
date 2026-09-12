<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * How much of a payment can still be given back (§26.3, D17).
 *
 * Computed from the refund rows every time rather than kept as a column on the
 * payment. A running total is a second copy of the truth, and the two disagree
 * exactly once — after which nobody can tell which was right.
 *
 * **Approved-but-unsent refunds count.** An approved decision is money already
 * promised away, and a refund the provider has taken but not yet settled is
 * further along than that. Leaving either out would let a second refund be
 * approved for the same money, and the provider would honour both.
 *
 * `RefundabilityPolicy` answers a different question — whether a *component* of
 * a payment may be refunded at all, under the rule in force. This answers how
 * much money is left, which is the one the provider cares about.
 */
class RefundableAmount
{
    /**
     * What is left.
     *
     * Never negative. A negative here would read as a refundable amount rather
     * than as the fault it would be.
     */
    public function handle(Payment $payment, ?RefundRequest $excluding = null): Money
    {
        $remaining = $payment->amount_minor->minus($this->claimed($payment, $excluding));

        return $remaining->isNegative()
            ? Money::of(0, $payment->amount_minor->currency)
            : $remaining;
    }

    /**
     * What refunds already hold — decided, in flight, or settled.
     *
     * `$excluding` leaves out one request's own claim, which is what a request
     * being sent needs: it is asking whether there is room for *itself*, and
     * counting its own approval would always say no.
     */
    public function claimed(Payment $payment, ?RefundRequest $excluding = null): Money
    {
        $minor = (int) RefundRequest::query()
            ->where('payment_id', $payment->id)
            ->whereIn('status', $this->holdingStatuses())
            ->unless($excluding === null, fn ($query) => $query->whereKeyNot($excluding?->id))
            ->sum('amount_minor');

        return Money::of($minor, Currency::from($payment->currency_code));
    }

    /**
     * Whether any of this payment has actually gone back.
     */
    public function hasRefunds(Payment $payment): bool
    {
        return RefundRequest::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Processed)
            ->exists();
    }

    /**
     * Whether the whole payment has gone back.
     *
     * Counts only what the provider confirmed. An approved refund that has not
     * been sent is not a refunded payment, and marking the payment refunded on
     * the strength of a decision would say money moved when it had not.
     */
    public function isFullyRefunded(Payment $payment): bool
    {
        $processed = (int) RefundRequest::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Processed)
            ->sum('amount_minor');

        return $processed >= $payment->amount_minor->minorUnits;
    }

    /**
     * The statuses that still have a claim on the payment's amount.
     *
     * `Requested` is deliberately absent: asking is not getting, and an open
     * request nobody has decided must not block a different refund from being
     * approved. The partial unique index already stops two open requests for
     * the same component.
     *
     * @return array<int, string>
     */
    protected function holdingStatuses(): array
    {
        return [
            RefundStatus::Approved->value,
            RefundStatus::Processed->value,
        ];
    }
}
