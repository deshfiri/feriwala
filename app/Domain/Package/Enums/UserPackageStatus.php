<?php

namespace App\Domain\Package\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * The state of an account's subscription (§8.2, §8.4).
 *
 * Declares its own legal moves, like every other status in the application. It
 * did not, and a subscription's status was therefore assignable to anything —
 * an expired term could be set back to Active without a payment, and a
 * superseded choice could be revived after the account had moved on. Neither is
 * reachable now.
 */
enum UserPackageStatus: string implements TransitionableState
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case RenewalDue = 'renewal_due';
    case GracePeriod = 'grace_period';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            /*
             * An unpaid choice either goes live, is replaced by a different
             * choice, or is abandoned. It cannot reach a renewal state: there
             * is no term to renew until one has started (§5.1).
             */
            self::PendingPayment => [self::Active, self::Superseded, self::Cancelled],

            self::Active => [self::RenewalDue, self::Cancelled, self::Expired],

            // §8.4 restores rather than severs: a renewal that arrives late
            // returns to Active rather than forcing a fresh purchase.
            self::RenewalDue => [self::Active, self::GracePeriod, self::Cancelled, self::Expired],
            self::GracePeriod => [self::Active, self::Expired, self::Cancelled],

            /*
             * Terminal. Expiry and cancellation are the end of a term, and a
             * new one is a new subscription with its own source and its own
             * captured terms — not this row brought back to life.
             */
            self::Expired, self::Cancelled, self::Superseded => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Active => 'Active',
            self::RenewalDue => 'Renewal due',
            self::GracePeriod => 'Grace period',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Superseded => 'Replaced',
        };
    }

    /**
     * Whether a subscription in this state grants the package's features.
     *
     * A renewal that is merely due, or one inside its grace period, still
     * entitles — §8.4 restores rather than severs, and cutting a partner off the
     * moment an invoice is late would strand live customer orders.
     *
     * An unpaid subscription entitles nothing: §5.1 requires payment before
     * activation, so granting features while awaiting payment would let someone
     * trade for free.
     */
    public function entitles(): bool
    {
        return in_array($this, self::entitling(), true);
    }

    /**
     * The statuses that grant anything, for querying rather than testing one.
     *
     * @return array<int, self>
     */
    public static function entitling(): array
    {
        return [self::Active, self::RenewalDue, self::GracePeriod];
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::RenewalDue, self::GracePeriod => 'warning',
            self::Expired, self::Cancelled => 'danger',
            self::PendingPayment => 'info',
            self::Superseded => 'neutral',
        };
    }
}
