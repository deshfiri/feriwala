<?php

namespace App\Domain\Billing;

use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use InvalidArgumentException;
use RoundingMode;

/**
 * The rates an administrator has set for taking Taka payments through
 * international gateways (D4, amended).
 *
 * A rate is **Taka per one unit** of the foreign currency — `120.50` for USD
 * means 1 USD = ৳120.50 — held as an exact decimal string and never a float. It
 * is the administrator's decision, entered by hand; nothing here fetches one.
 *
 * Two directions, and the rounding of each is stated rather than defaulted:
 *
 *   - **Charging** a Taka amount rounds the foreign figure **up** to the cent,
 *     so the platform never collects less than the Taka it is owed.
 *   - **Refunding** a Taka share rounds the foreign figure **down**, so the sum
 *     of any number of partial refunds can never exceed what was charged.
 *
 * Only Taka converts into a foreign currency. A payment already in a currency
 * the gateway accepts is sent as it is, and a payment in a foreign currency is
 * never converted into another.
 */
class ExchangeRates
{
    /** The setting holding `{"USD": "120.50", ...}`. */
    public const SETTING = 'payment.fx.rates';

    /** Taka per unit: up to eight digits, up to six decimal places, above zero. */
    private const PATTERN = '/^\d{1,8}(\.\d{1,6})?$/';

    private const WORKING_SCALE = 12;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * Every rate that is set, keyed by currency code.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = $this->settings->get(self::SETTING, []);

        if (! is_array($stored)) {
            return [];
        }

        $rates = [];

        foreach (self::convertible() as $currency) {
            $value = $stored[$currency->value] ?? null;

            if (is_string($value) && self::isValidRate($value)) {
                $rates[$currency->value] = $value;
            }
        }

        return $rates;
    }

    /**
     * Taka per one unit of the currency, or null when none has been set.
     */
    public function rateFor(Currency $currency): ?string
    {
        return $this->all()[$currency->value] ?? null;
    }

    public function hasRate(Currency $currency): bool
    {
        return $this->rateFor($currency) !== null;
    }

    /**
     * The foreign currencies a rate can be set for: everything but Taka.
     *
     * @return list<Currency>
     */
    public static function convertible(): array
    {
        return array_values(array_filter(
            Currency::cases(),
            fn (Currency $currency) => $currency !== Currency::base(),
        ));
    }

    public static function isValidRate(string $rate): bool
    {
        if (preg_match(self::PATTERN, $rate) !== 1) {
            return false;
        }

        assert(is_numeric($rate));

        return bccomp($rate, '0', 6) > 0;
    }

    /**
     * What a gateway is asked for to collect this much Taka — rounded up.
     */
    public function chargeFor(Money $base, Currency $to, string $rate): Money
    {
        $this->guard($base, $to, $rate);

        $quotient = bcdiv($base->amount, $rate, self::WORKING_SCALE);

        return Money::fromDecimal($quotient, $to, RoundingMode::PositiveInfinity);
    }

    /**
     * The foreign figure for a Taka share of a payment taken at `$rate` — rounded
     * down, so partial refunds never add up to more than was charged.
     */
    public function shareOf(Money $base, Currency $to, string $rate): Money
    {
        $this->guard($base, $to, $rate);

        $quotient = bcdiv($base->amount, $rate, self::WORKING_SCALE);

        return Money::fromDecimal($quotient, $to, RoundingMode::NegativeInfinity);
    }

    private function guard(Money $base, Currency $to, string $rate): void
    {
        if ($base->currency !== Currency::base()) {
            throw new InvalidArgumentException('Only a Taka amount is converted for a gateway.');
        }

        if ($to === Currency::base()) {
            throw new InvalidArgumentException('There is nothing to convert Taka into Taka.');
        }

        if (! self::isValidRate($rate)) {
            throw new InvalidArgumentException("[{$rate}] is not a usable exchange rate.");
        }
    }
}
