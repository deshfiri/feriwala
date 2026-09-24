<?php

namespace App\Domain\Referral;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Referral\Enums\CommissionBase;
use App\Support\Money\Money;

/**
 * What a percentage reward is a percentage of, in exact flat-Taka {@see Money} (D24, P7-42, D26).
 *
 * From the qualifying payment's own allocations — what was charged and paid —
 * never from a figure anyone typed. The chosen lines are summed; a discount on
 * the payment is shared across all its revenue lines in proportion, and the
 * share falling on the chosen lines is rounded **up**, so the base rounds down
 * and nothing is calculated on money that was not paid. Tax and a deposit are
 * never part of it.
 */
class CommissionBaseCalculator
{
    /**
     * Working precision for the discount-share ratio, well past the
     * currency's own scale, so truncating to it can only ever discard digits
     * that were genuinely insignificant — never a digit the ceiling below
     * depends on.
     */
    private const RATIO_GUARD_SCALE = 20;

    public function for(Payment $payment, CommissionBase $base): Money
    {
        $payment->loadMissing('allocations');

        $currency = $payment->amount->currency;
        $chosen = Money::zero($currency);
        $revenue = Money::zero($currency);
        $discount = Money::zero($currency);

        /** @var PaymentAllocation $allocation */
        foreach ($payment->allocations as $allocation) {
            $amount = $allocation->amount;

            if ($allocation->type === AllocationType::Discount) {
                $discount = $discount->plus($amount);

                continue;
            }

            if ($allocation->type->isRevenue()) {
                $revenue = $revenue->plus($amount);
            }

            if (in_array($allocation->type, $base->allocations(), true)) {
                $chosen = $chosen->plus($amount);
            }
        }

        if (! $chosen->isPositive() || ! $revenue->isPositive()) {
            return Money::zero($currency);
        }

        $share = $discount->isZero() ? Money::zero($currency) : $this->shareOfDiscount($chosen, $discount, $revenue);
        $result = $chosen->minus($share);

        return $result->isNegative() ? Money::zero($currency) : $result;
    }

    /**
     * The chosen lines' share of the discount — `chosen × discount ÷ revenue`
     * — rounded up to the currency's own scale by computing the exact
     * quotient at guard precision, then adding one smallest unit whenever
     * truncating it to scale would have discarded anything at all.
     */
    private function shareOfDiscount(Money $chosen, Money $discount, Money $revenue): Money
    {
        $currency = $chosen->currency;
        $scale = $currency->scale();

        $product = bcmul($chosen->toDecimal(), $discount->toDecimal(), self::RATIO_GUARD_SCALE);
        $exact = bcdiv($product, $revenue->toDecimal(), self::RATIO_GUARD_SCALE);
        $truncated = bcadd($exact, '0', $scale);

        $ceiled = bccomp($exact, $truncated, self::RATIO_GUARD_SCALE) > 0
            ? bcadd($truncated, $currency->smallestUnit(), $scale)
            : $truncated;

        return Money::fromDecimal($ceiled, $currency);
    }
}
