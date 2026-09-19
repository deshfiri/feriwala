<?php

namespace App\Domain\Referral;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Referral\Enums\CommissionBase;

/**
 * What a percentage reward is a percentage of, in minor units (D24, P7-42).
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
    public function for(Payment $payment, CommissionBase $base): int
    {
        $payment->loadMissing('allocations');

        $chosen = 0;
        $revenue = 0;
        $discount = 0;

        /** @var PaymentAllocation $allocation */
        foreach ($payment->allocations as $allocation) {
            $minor = $allocation->amount_minor->minorUnits;

            if ($allocation->type === AllocationType::Discount) {
                $discount += $minor;

                continue;
            }

            if ($allocation->type->isRevenue()) {
                $revenue += $minor;
            }

            if (in_array($allocation->type, $base->allocations(), true)) {
                $chosen += $minor;
            }
        }

        if ($chosen <= 0 || $revenue <= 0) {
            return 0;
        }

        // The chosen lines' share of the discount, rounded up.
        $share = $discount === 0 ? 0 : intdiv($chosen * $discount + $revenue - 1, $revenue);

        return max(0, $chosen - $share);
    }
}
