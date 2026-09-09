<?php

namespace App\Domain\Package;

use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\SettingsRepository;
use Throwable;

/**
 * The operational rules around a subscription's term (§8.2, §8.4).
 *
 * Configurable through the settings table — the same source the KYC deadlines
 * and the session lifetime already use — because "start reminding people a
 * fortnight out instead of a month" is an operational decision, not a deploy.
 * Nothing here introduces a second configuration system, and nothing here
 * touches money: what a term costs comes from its captured terms.
 */
class SubscriptionPolicy
{
    /** Days before expiry that a term becomes renewable and starts nagging. */
    public const RENEWAL_WINDOW = 'package.renewal_window_days';

    public const DEFAULT_RENEWAL_WINDOW_DAYS = 30;

    /**
     * Bounds, so a mistyped setting cannot open renewal from the first day of a
     * term or close it until the morning it ends.
     */
    public const MINIMUM_WINDOW_DAYS = 1;

    public const MAXIMUM_WINDOW_DAYS = 180;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * How early a term may be renewed, in days before it expires.
     */
    public function renewalWindowDays(): int
    {
        try {
            $setting = $this->settings->get(self::RENEWAL_WINDOW);
        } catch (Throwable) {
            /*
             * Read on screens an account reaches while its term is running out.
             * A settings store that cannot be answered must fall back to the
             * default rather than turn the renewal page into a 500 — the one
             * page somebody in that position actually needs.
             */
            return self::DEFAULT_RENEWAL_WINDOW_DAYS;
        }

        if (! is_numeric($setting)) {
            return self::DEFAULT_RENEWAL_WINDOW_DAYS;
        }

        return max(
            self::MINIMUM_WINDOW_DAYS,
            min(self::MAXIMUM_WINDOW_DAYS, (int) $setting),
        );
    }

    /**
     * Whether this term can be renewed right now.
     *
     * Four states qualify. A live term inside its renewal window, one already
     * marked due, one in its grace period, and one that has run out — §8.4 is
     * explicit that an account keeps "limited access to Payment and renewal
     * modules" after expiry, and a renewal page that closes at the moment of
     * expiry is a page that closes exactly when it is needed.
     *
     * A cancelled or superseded term is not renewable. Neither is a term still
     * awaiting its first payment: there is nothing to carry on from yet.
     */
    public function isRenewable(UserPackage $subscription): bool
    {
        return match ($subscription->status) {
            UserPackageStatus::RenewalDue,
            UserPackageStatus::GracePeriod,
            UserPackageStatus::Expired => true,

            UserPackageStatus::Active => $this->isInsideRenewalWindow($subscription),

            UserPackageStatus::PendingPayment,
            UserPackageStatus::Cancelled,
            UserPackageStatus::Superseded => false,
        };
    }

    /**
     * Whether an active term is close enough to its end to be renewed.
     *
     * A term with no expiry never enters the window: there is nothing to renew
     * before, and letting somebody buy a second endless term would take money
     * for nothing.
     */
    public function isInsideRenewalWindow(UserPackage $subscription): bool
    {
        if ($subscription->expires_at === null) {
            return false;
        }

        return now()->gte(
            $subscription->expires_at->subDays($this->renewalWindowDays()),
        );
    }

    /**
     * Why renewal is unavailable, in the account holder's own words.
     *
     * Named rather than implied: a missing button that will not say why sends
     * somebody to support to find out.
     */
    public function renewalBlocker(?UserPackage $subscription): ?string
    {
        if ($subscription === null) {
            return __('package.renewal.no_subscription');
        }

        if ($this->isRenewable($subscription)) {
            return null;
        }

        return match ($subscription->status) {
            UserPackageStatus::PendingPayment => __('package.renewal.awaiting_first_payment'),
            UserPackageStatus::Cancelled, UserPackageStatus::Superseded => __('package.renewal.term_closed'),
            default => $subscription->expires_at === null
                ? __('package.renewal.no_expiry')
                : __('package.renewal.too_early', [
                    'days' => $this->renewalWindowDays(),
                ]),
        };
    }
}
