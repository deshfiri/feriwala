<?php

declare(strict_types=1);

namespace Storefront\Support;

/**
 * Showing money the ERP sent. It never does arithmetic on a price: every figure
 * a customer sees or is charged was worked out by the ERP, and this only
 * formats the decimal string it handed over — with no float anywhere.
 */
final class Money
{
    private const SYMBOLS = ['BDT' => '৳', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    /**
     * @param  array<string, mixed>|null  $money  `{amount, currency, formatted?}` as the API sends it
     */
    public static function display(?array $money): string
    {
        if ($money === null) {
            return '';
        }

        // The ERP's own wording is the one to show when it sent one.
        if (isset($money['formatted']) && is_string($money['formatted']) && $money['formatted'] !== '') {
            return self::localiseDigits($money['formatted']);
        }

        $amount = (string) ($money['amount'] ?? '0');
        $currency = (string) ($money['currency'] ?? '');

        return self::localiseDigits((self::SYMBOLS[$currency] ?? $currency.' ').self::group($amount));
    }

    /**
     * "1234.5" → "1,234.50", with no float involved.
     */
    public static function group(string $amount): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            return $amount;
        }

        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $m[2]);
        $fraction = str_pad(substr($m[3] ?? '', 0, 2), 2, '0');

        return $m[1].$integer.'.'.$fraction;
    }

    public static function isZero(?array $money): bool
    {
        return $money === null || preg_match('/^-?0+(\.0+)?$/', (string) ($money['amount'] ?? '0')) === 1;
    }

    /**
     * Western digits become Bangla ones for a Bangla reader.
     */
    public static function localiseDigits(string $text): string
    {
        if (I18n::locale() !== 'bn') {
            return $text;
        }

        return strtr($text, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    }
}
