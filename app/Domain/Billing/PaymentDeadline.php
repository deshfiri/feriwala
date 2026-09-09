<?php

namespace App\Domain\Billing;

use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * How long an unpaid checkout stays open (§9).
 *
 * §9 lists "Payment deadline" among the things an administrator configures, and
 * leaves the number to them. **Opt-in, like the KYC deadline**: with
 * `billing.payment_deadline_hours` unset there is no deadline at all, which is
 * the right behaviour before anybody has decided what the window should be.
 * Inventing seventy-two hours on their behalf would start cancelling real
 * checkouts on a number nobody agreed.
 *
 * Bounded when it is set, so a mistyped value cannot close a checkout within
 * minutes of it opening or hold a coupon slot for a year.
 */
class PaymentDeadline
{
    /** Hours an applicant has to pay. Unset or zero means no deadline. */
    public const HOURS = 'billing.payment_deadline_hours';

    /** An hour is the shortest window somebody could realistically pay inside. */
    public const MINIMUM_HOURS = 1;

    /** Thirty days. Beyond that a reservation is not being held, it is stuck. */
    public const MAXIMUM_HOURS = 720;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->hours() !== null;
    }

    /**
     * The configured window, or null when checkouts do not expire.
     */
    public function hours(): ?int
    {
        $hours = $this->settings->get(self::HOURS);

        if (! is_numeric($hours) || (int) $hours <= 0) {
            return null;
        }

        return max(self::MINIMUM_HOURS, min(self::MAXIMUM_HOURS, (int) $hours));
    }

    /**
     * When a checkout opened now would run out, or null when none do.
     *
     * Called once, at the moment the payment is recorded, and the answer is
     * stored on the row. An applicant told they have until Friday must still
     * have until Friday if the window is shortened on Wednesday.
     */
    public function from(?CarbonImmutable $openedAt = null): ?CarbonImmutable
    {
        $hours = $this->hours();

        if ($hours === null) {
            return null;
        }

        return ($openedAt ?? CarbonImmutable::now())->addHours($hours);
    }
}
