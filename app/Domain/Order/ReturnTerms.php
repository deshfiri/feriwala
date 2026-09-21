<?php

namespace App\Domain\Order;

use App\Domain\Settings\SettingsRepository;

/**
 * How long a customer has to send something back (§18.2, P6-12).
 *
 * The specification leaves the length to Feriwala, so it is a setting with a
 * default rather than a constant: **seven days** from delivery, which is the
 * common expectation in this market, adjustable by the platform.
 *
 * Bounded, because both ends are harmful: a window of zero makes the returns
 * flow unreachable without saying so, and one of years leaves stock and
 * commissions permanently unsettled (§22.2 pays on return-period completion).
 */
class ReturnTerms
{
    public const WINDOW_DAYS = 'orders.return_window_days';

    public const DEFAULT_WINDOW_DAYS = 7;

    public const MINIMUM_WINDOW_DAYS = 1;

    /** Three months: longer is not a return, it is a warranty claim. */
    public const MAXIMUM_WINDOW_DAYS = 90;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function windowDays(): int
    {
        $value = $this->settings->get(self::WINDOW_DAYS);

        if (! is_numeric($value) || (int) $value <= 0) {
            return self::DEFAULT_WINDOW_DAYS;
        }

        return max(self::MINIMUM_WINDOW_DAYS, min(self::MAXIMUM_WINDOW_DAYS, (int) $value));
    }
}
