<?php

namespace App\Domain\Withdrawal;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * How much a Client/Partner `BusinessAccount` may withdraw at once (§27),
 * mirroring {@see SupplierWithdrawalLimits} exactly for
 * a different owner.
 *
 * The default is a global bounded setting; an account may also carry its
 * own override — set only by an Admin, on the account itself, so "one
 * account needs a higher limit" never touches any other account or any
 * shared rule-resolution system.
 */
class AccountWithdrawalLimits
{
    public const MINIMUM_SETTING = 'account.withdrawal_minimum';

    public const MAXIMUM_SETTING = 'account.withdrawal_maximum';

    /** BDT 500: small enough not to lock out a new account's first request. */
    public const DEFAULT_MINIMUM = '500.00';

    /** No default ceiling — an account may withdraw everything available. */
    public const DEFAULT_MAXIMUM = null;

    public const FLOOR_MINIMUM = '1.00';

    public const CEILING_MAXIMUM = '1000000000.00';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function minimumFor(BusinessAccount $account, Currency $currency): Money
    {
        if ($account->withdrawal_minimum_override !== null) {
            return Money::fromDecimal($account->withdrawal_minimum_override, $currency);
        }

        $value = $this->settings->get(self::MINIMUM_SETTING);
        $decimal = is_string($value) && $value !== '' ? $value : self::DEFAULT_MINIMUM;

        $requested = Money::fromDecimal($decimal, $currency);
        $floor = Money::fromDecimal(self::FLOOR_MINIMUM, $currency);

        return $requested->greaterThan($floor) ? $requested : $floor;
    }

    public function maximumFor(BusinessAccount $account, Currency $currency): ?Money
    {
        if ($account->withdrawal_maximum_override !== null) {
            return Money::fromDecimal($account->withdrawal_maximum_override, $currency);
        }

        $value = $this->settings->get(self::MAXIMUM_SETTING);

        if (! is_string($value) || $value === '' || ! Money::fromDecimal($value, $currency)->isPositive()) {
            return null;
        }

        $requested = Money::fromDecimal($value, $currency);
        $ceiling = Money::fromDecimal(self::CEILING_MAXIMUM, $currency);

        return $requested->lessThan($ceiling) ? $requested : $ceiling;
    }
}
