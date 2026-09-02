<?php

namespace App\Domain\Package\Enums;

/**
 * The state of an account's subscription (§8.2, §8.4).
 */
enum UserPackageStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case RenewalDue = 'renewal_due';
    case GracePeriod = 'grace_period';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

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
        return in_array($this, [self::Active, self::RenewalDue, self::GracePeriod], true);
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
