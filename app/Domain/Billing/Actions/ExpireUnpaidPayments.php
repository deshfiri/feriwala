<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Closes checkouts that ran out of time (§9).
 *
 * **Never a settled payment.** The cancellation is made through the status map
 * ({@see PaymentStatus::transitionsTo()}), which gives `Paid` no move to
 * `Cancelled` at all — so money that arrived cannot be cancelled by a sweep
 * however the query is written. The status is re-read under a row lock before
 * the move, because a payment can settle between the query and the write.
 *
 * **Idempotent by transition**, the way the subscription sweep is: a second pass
 * finds the payment already cancelled and does nothing. No marker table, no
 * "swept_at" column that could disagree with the status.
 *
 * **Only `Draft` and `Initiated`.** A `Pending` payment is money in flight — a
 * bank transfer or a risk review that a gateway will still confirm — and
 * cancelling it would abandon a payment that is on its way.
 *
 * Expiry releases what this checkout was holding and nothing else: the coupon
 * slot reserved against **this payment**, by payment id. The package selection
 * is deliberately left alone — the applicant chose it, and taking it away would
 * make missing a deadline cost them their place in the funnel as well as their
 * checkout.
 */
class ExpireUnpaidPayments
{
    /** The statuses a deadline may close. */
    public const EXPIRABLE = [PaymentStatus::Draft, PaymentStatus::Initiated];

    public function __construct(
        protected SettleCouponRedemption $couponRedemptions,
        protected DatabaseManager $database,
    ) {}

    /**
     * The scheduled pass over every overdue checkout.
     *
     * @return int how many were closed
     */
    public function handle(): int
    {
        $closed = 0;

        $this->overdue()->chunkById(200, function ($payments) use (&$closed) {
            foreach ($payments as $payment) {
                if ($this->expire($payment)) {
                    $closed++;
                }
            }
        });

        return $closed;
    }

    /**
     * Close the overdue checkouts for one thing being bought.
     *
     * Called before a fresh checkout is recorded, so an applicant returning
     * after the deadline starts a new attempt rather than reviving the dead one
     * through its idempotency key.
     */
    public function forPayable(Model $payable): int
    {
        $closed = 0;

        $payments = $this->overdue()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->get();

        foreach ($payments as $payment) {
            if ($this->expire($payment)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Close one checkout, if it is still open and still overdue.
     */
    public function expire(Payment $payment): bool
    {
        $closed = (bool) $this->database->transaction(function () use ($payment) {
            /** @var Payment|null $locked */
            $locked = Payment::query()->lockForUpdate()->find($payment->id);

            if ($locked === null || $locked->expires_at === null) {
                return false;
            }

            // Re-read under the lock. The gateway may have confirmed this
            // between the query that selected it and this write.
            if (! in_array($locked->status, self::EXPIRABLE, true)
                || ! $locked->canTransitionTo(PaymentStatus::Cancelled)
                || $locked->expires_at->greaterThan(CarbonImmutable::now())) {
                return false;
            }

            $locked->transitionTo(PaymentStatus::Cancelled);

            $locked->forceFill([
                'cancelled_at' => now(),

                /*
                 * The key is released with the attempt it belonged to. It exists
                 * to collapse a double submit of *one* checkout; leaving it on a
                 * dead payment would bind the applicant's next attempt to a
                 * cancelled row and a total that is no longer current.
                 */
                'idempotency_key' => null,
            ])->save();

            $payment->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });

        if ($closed) {
            // Only this checkout's hold. Another account's reservation on the
            // same coupon is not this payment's to give back.
            $this->couponRedemptions->release($payment);
        }

        return $closed;
    }

    /**
     * @return Builder<Payment>
     */
    protected function overdue(): Builder
    {
        return Payment::query()
            ->whereIn('status', self::EXPIRABLE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', CarbonImmutable::now());
    }
}
