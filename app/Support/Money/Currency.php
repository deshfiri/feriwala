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
     * The smallest amount this currency can express, as an exact decimal string
     * — "0.01" for BDT. Used to hand out an allocation remainder a step at a
     * time. This is a unit of the currency itself, not a separate minor
     * denomination: Feriwala stores no minor units anywhere (D26).
     */
    public function smallestUnit(): string
    {
        $scale = $this->scale();

        return $scale === 0
            ? '1'
            : '0.'.str_repeat('0', $scale - 1).'1';
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
}
