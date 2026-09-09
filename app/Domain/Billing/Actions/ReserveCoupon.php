<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\RedemptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\CouponRedemption;
use App\Domain\Billing\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Holds one use of a coupon against a payment (§9).
 *
 * A reservation is not a redemption. It is taken when a payment is **recorded**
 * — the moment somebody commits to paying — and it occupies a slot against the
 * coupon's limits so two checkouts started at once cannot both spend the last
 * one. It becomes a redemption only when the money arrives
 * ({@see RedeemCoupon}), and goes back if it never does
 * ({@see ReleaseCoupon}).
 *
 * Checking a code to see what it is worth reserves nothing. That happens on
 * every checkout render, and a code spent by looking at it would be spent by
 * anybody who opened the page.
 *
 * **The coupon row is locked, then the limits are counted.** Reading the count
 * first and locking after is the classic way to oversell the last slot: two
 * requests both read "one left" and both insert. The lock makes them queue, so
 * the second reads what the first wrote.
 *
 * Idempotent on the payment. `payment_id` is unique, so a retried submission
 * reserves once and the index settles it rather than a check the retry would
 * also pass.
 */
class ReserveCoupon
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function handle(
        Coupon $coupon,
        BusinessAccount $account,
        Payment $payment,
        Money $amount,
    ): CouponRedemption {
        $existing = CouponRedemption::query()->where('payment_id', $payment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(
                fn () => $this->reserve($coupon, $account, $payment, $amount)
            );
        } catch (UniqueConstraintViolationException $exception) {
            $reservation = CouponRedemption::query()->where('payment_id', $payment->id)->first();

            if ($reservation === null) {
                throw $exception;
            }

            return $reservation;
        }
    }

    protected function reserve(
        Coupon $coupon,
        BusinessAccount $account,
        Payment $payment,
        Money $amount,
    ): CouponRedemption {
        /** @var Coupon $locked */
        $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

        // Re-checked inside the lock, not trusted from the render that offered
        // it: the window can close and the last slot can go between the two.
        if (! $locked->isOpen()) {
            throw new RuntimeException('That coupon is no longer available.');
        }

        $remaining = $locked->remainingUses();

        if ($remaining !== null && $remaining < 1) {
            throw new RuntimeException('That coupon has been fully used.');
        }

        if ($locked->per_account_limit !== null
            && $locked->usesBy($account->id) >= $locked->per_account_limit) {
            throw new RuntimeException('This account has already used that coupon.');
        }

        $reservation = CouponRedemption::create([
            'coupon_id' => $locked->id,
            'business_account_id' => $account->id,
            'payment_id' => $payment->id,
            'status' => RedemptionStatus::Reserved,
            // Snapshotted: the coupon can be edited afterwards and this has to
            // go on reconciling with the payment allocation it produced.
            'amount_minor' => $amount,
            'currency_code' => $amount->currency->value,
            'reserved_at' => now(),
        ]);

        // Incremented on the locked row rather than recounted, so the counter
        // and the rows cannot disagree under concurrency.
        $locked->forceFill(['redeemed_count' => $locked->redeemed_count + 1])->save();

        return $reservation;
    }
}
