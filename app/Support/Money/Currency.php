<?php

namespace App\Support\Money;

/**
 * Supported currencies.
 *
 * Version 1 operates in BDT only (decision D4). The other cases exist so the
 * schema and ledger stay multi-currency-ready and so international gateways can
 * record an original currency without the base ledger being rewritten. No
 * exchange-rate accounting is performed in version 1.
 */
enum Currency: string
{
    case BDT = 'BDT';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';

    /**
     * The platform's base and operational currency (D4).
     */
    public static function base(): self
    {
        return self::BDT;
    }

    /**
     * Number of decimal places this currency subdivides into.
     */
    public function scale(): int
    {
        return match ($this) {
            self::BDT, self::USD, self::EUR, self::GBP => 2,
        };
    }

    /**
     * How many minor units make one major unit.
     */
    public function minorUnitFactor(): int
    {
        return 10 ** $this->scale();
    }

    /**
     * The display symbol for this currency.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::BDT => '৳',
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
        };
    }

    /**
     * The name of this currency's minor unit, for labels and receipts.
     */
    public function minorUnitName(): string
    {
        return match ($this) {
            self::BDT => 'poisha',
            self::USD, self::EUR => 'cents',
            self::GBP => 'pence',
        };
    }
}
