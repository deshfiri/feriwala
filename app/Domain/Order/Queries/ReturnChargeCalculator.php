<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;
use App\Support\Money\Money;
use RoundingMode;

/**
 * What a return costs, worked out from the order's own immutable snapshot (D35).
 *
 * **The original delivery charge is history.** It is the delivery figure the
 * order was placed with — a locked column, so no later setting can change what
 * the original delivery cost — and it is never charged again: whatever was
 * collected for it stays collected, and a return or an exchange adds only its
 * own new costs.
 *
 * The return charge is half of that figure. Exact, in flat Taka: the product is
 * taken with bcmath and rounded **half away from zero** to the poisha, stated
 * here rather than left to a default, so ৳75.00 gives ৳37.50 and a single
 * poisha gives a single poisha. A free delivery has no return charge to levy.
 */
class ReturnChargeCalculator
{
    /** Half of the original delivery charge, as a percentage. */
    public const RETURN_CHARGE_PERCENT = '50';

    /**
     * What the original delivery cost, as the order snapshotted it.
     */
    public function originalDelivery(Order $order): Money
    {
        return $order->delivery;
    }

    /**
     * The charge for the parcel coming back.
     */
    public function returnCharge(Order $order): Money
    {
        return $this->originalDelivery($order)
            ->percentage(self::RETURN_CHARGE_PERCENT, RoundingMode::HalfAwayFromZero);
    }
}
