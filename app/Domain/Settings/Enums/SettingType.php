<?php

namespace App\Domain\Settings\Enums;

use App\Support\Money\Money;

/**
 * How a stored setting value is interpreted.
 *
 * Settings arrive from form input as strings. Casting them here means a caller
 * asking for a deadline gets an integer, not `"7"` — and a caller asking for a
 * fee gets {@see Money}, never a float.
 */
enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Decimal = 'decimal';
    case Money = 'money';
    case Json = 'json';

    /**
     * Turn a stored string into its typed value.
     */
    public function cast(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String => $value,
            self::Integer => (int) $value,
            // "0", "false", "off", and "" are all false; everything else true.
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
            // Kept as a string so a rate never becomes a float before it is used
            // in a money calculation.
            self::Decimal => $value,
            self::Money => Money::of((int) $value),
            self::Json => json_decode($value, true) ?? [],
        };
    }

    /**
     * Turn a typed value into the string that is stored.
     */
    public function serialise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Decimal => (string) $value,
            self::Integer => (string) (int) $value,
            self::Boolean => $value ? '1' : '0',
            self::Money => (string) ($value instanceof Money
                ? $value->minorUnits
                : (int) $value),
            self::Json => (string) json_encode($value),
        };
    }
}
