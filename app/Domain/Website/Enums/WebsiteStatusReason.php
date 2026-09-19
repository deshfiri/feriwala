<?php

namespace App\Domain\Website\Enums;

/**
 * Why the platform itself moved a website (§16.4, §24.3).
 *
 * Billing and the lifecycle sweep write one of these as the reason on a status
 * change. It is a code, not prose, so the history is read in the reader's
 * language: staff see {@see label()}, and the partner sees {@see note()} — the
 * note in the language they are reading now, not the one the scheduler happened
 * to run in. A person moving a website writes their own reason instead.
 */
enum WebsiteStatusReason: string
{
    case ChargesSettled = 'charges_settled';
    case InsufficientBalance = 'insufficient_balance';
    case Renewed = 'renewed';
    case PackageLapsed = 'package_lapsed';
    case GraceEnded = 'grace_ended';
    case PackageRestored = 'package_restored';
    case BalanceBelowMinimum = 'balance_below_minimum';
    case BalanceRestored = 'balance_restored';
    case DomainExpired = 'domain_expired';
    case HostingExpired = 'hosting_expired';
    case DomainRenewalDue = 'domain_renewal_due';
    case HostingRenewalDue = 'hosting_renewal_due';

    /**
     * What staff read in the history.
     */
    public function label(): string
    {
        return __('website.reasons.'.$this->value);
    }

    /**
     * What the partner reads in the history.
     */
    public function note(): string
    {
        return __('website.notes.'.match ($this) {
            self::InsufficientBalance => 'deposit_pending',
            self::PackageLapsed => 'grace_period',
            self::GraceEnded => 'package_expired',
            self::PackageRestored, self::BalanceRestored => 'restored',
            self::BalanceBelowMinimum => 'low_balance',
            default => $this->value,
        });
    }
}
