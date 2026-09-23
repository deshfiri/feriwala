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
    public const MINIMUM_SETTING = 'supplier.withdrawal_minimum';

    public const MAXIMUM_SETTING = 'supplier.withdrawal_maximum';

    /** BDT 500: small enough not to lock out a new Supplier's first request. */
    public const DEFAULT_MINIMUM = '500.00';

    /** No default ceiling — a Supplier may withdraw everything available. */
    public const DEFAULT_MAXIMUM = null;

    public const FLOOR_MINIMUM = '1.00';

    public const CEILING_MAXIMUM = '1000000000.00';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function minimumFor(Supplier $supplier, Currency $currency): Money
    {
        if ($supplier->withdrawal_minimum_override !== null) {
            return Money::fromDecimal($supplier->withdrawal_minimum_override, $currency);
        }

        $value = $this->settings->get(self::MINIMUM_SETTING);
        $decimal = is_string($value) && $value !== '' ? $value : self::DEFAULT_MINIMUM;

        $requested = Money::fromDecimal($decimal, $currency);
        $floor = Money::fromDecimal(self::FLOOR_MINIMUM, $currency);

        return $requested->greaterThan($floor) ? $requested : $floor;
    }

    public function maximumFor(Supplier $supplier, Currency $currency): ?Money
    {
        if ($supplier->withdrawal_maximum_override !== null) {
            return Money::fromDecimal($supplier->withdrawal_maximum_override, $currency);
        }

        $value = $this->settings->get(self::MAXIMUM_SETTING);

        if (! is_string($value) || $value === '' || ! Money::fromDecimal($value, $currency)->isPositive()) {
            // No default ceiling: a Supplier may withdraw everything available.
            return null;
        }

        $requested = Money::fromDecimal($value, $currency);
        $ceiling = Money::fromDecimal(self::CEILING_MAXIMUM, $currency);

        return $requested->lessThan($ceiling) ? $requested : $ceiling;
    }
}
