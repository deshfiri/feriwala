<?php

namespace App\Domain\Supplier;

use App\Domain\Inventory\ReservationWindows;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Models\Supplier;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * How much a Supplier may withdraw at once (D25, P13-24).
 *
 * The default is a global bounded setting, the same pattern
 * {@see ReservationWindows} uses. A Supplier may also
 * carry its own override — set only by an Admin, on the Supplier itself, so
 * "one Supplier needs a higher limit" never touches any other account type
 * or any shared rule-resolution system.
 */
class SupplierWithdrawalLimits
{
    public const MINIMUM_MINOR = 'supplier.withdrawal_minimum_minor';

    public const MAXIMUM_MINOR = 'supplier.withdrawal_maximum_minor';

    /** 500 taka: small enough not to lock out a new Supplier's first request. */
    public const DEFAULT_MINIMUM_MINOR = 50000;

    /** No default ceiling — a Supplier may withdraw everything available. */
    public const DEFAULT_MAXIMUM_MINOR = null;

    public const FLOOR_MINIMUM_MINOR = 100;

    public const CEILING_MAXIMUM_MINOR = 100_000_000_00;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function minimumFor(Supplier $supplier, Currency $currency): Money
    {
        if ($supplier->withdrawal_minimum_override_minor !== null) {
            return Money::of((int) $supplier->withdrawal_minimum_override_minor, $currency);
        }

        $value = $this->settings->get(self::MINIMUM_MINOR);
        $minor = is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_MINIMUM_MINOR;

        return Money::of(max(self::FLOOR_MINIMUM_MINOR, $minor), $currency);
    }

    public function maximumFor(Supplier $supplier, Currency $currency): ?Money
    {
        if ($supplier->withdrawal_maximum_override_minor !== null) {
            return Money::of((int) $supplier->withdrawal_maximum_override_minor, $currency);
        }

        $value = $this->settings->get(self::MAXIMUM_MINOR);

        if (! is_numeric($value) || (int) $value <= 0) {
            // No default ceiling: a Supplier may withdraw everything available.
            return null;
        }

        return Money::of(min(self::CEILING_MAXIMUM_MINOR, (int) $value), $currency);
    }
}
