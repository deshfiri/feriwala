<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\RedemptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\CouponRedemption;
use App\Domain\Billing\Models\Payment;
use Illuminate\Database\DatabaseManager;

/**
 * Turns a held coupon into a used one, or gives the slot back (§9).
 *
 * Both halves live here because they are the same decision read two ways: the
 * payment either arrived or it did not, and exactly one of these follows. Split
 * across two classes they would drift on what "already settled" means.
 *
 * **Redeeming is permanent and releasing is not a deletion.** A released
 * reservation keeps its row with a released timestamp: "this account tried to
 * use that code in March and did not pay" is worth being able to see, and a
 * deleted row says nothing at all.
 *
 * Both are idempotent under a row lock. A gateway sends the same notification
 * several times, and a slot given back twice would inflate the coupon's
 * remaining uses.
 */
class SettleCouponRedemption
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * The money arrived. The hold becomes a use.
     */
    public function redeem(Payment $payment): ?CouponRedemption
    {
        return $this->move($payment, RedemptionStatus::Redeemed);
    }

    /**
     * The checkout expired or failed. The slot goes back.
     */
    public function release(Payment $payment): ?CouponRedemption
    {
        return $this->move($payment, RedemptionStatus::Released);
    }

    protected function move(Payment $payment, RedemptionStatus $to): ?CouponRedemption
    {
        return $this->database->transaction(function () use ($payment, $to) {
            $reservation = CouponRedemption::query()
                ->lockForUpdate()
                ->where('payment_id', $payment->id)
                ->first();

            // Only a held reservation moves. One already redeemed or released
            // is left exactly as it is, which is what makes a repeated callback
            // free of side effects.
            if ($reservation === null || $reservation->status !== RedemptionStatus::Reserved) {
                return $reservation;
            }

            $reservation->forceFill([
                'status' => $to,
                'redeemed_at' => $to === RedemptionStatus::Redeemed ? now() : null,
                'released_at' => $to === RedemptionStatus::Released ? now() : null,
            ])->save();

            if ($to === RedemptionStatus::Released) {
                $this->returnSlot($reservation->coupon_id);
            }

            return $reservation;
        });
    }

    /**
     * Give one use back to the coupon.
     *
     * Floored at zero. A counter that could go negative would hand out more
     * uses than the limit allows the next time somebody looked at it.
     */
    protected function returnSlot(int $couponId): void
    {
        $coupon = Coupon::query()->lockForUpdate()->find($couponId);

        $coupon?->forceFill([
            'redeemed_count' => max($coupon->redeemed_count - 1, 0),
        ])->save();
    }
}
