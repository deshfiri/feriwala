<?php

namespace App\Support\References;

use App\Concerns\HasPartnerNumber;
use Random\RandomException;

/**
 * A partner's identity number: `BJ` followed by eleven digits, such as
 * `BJ56987412548`.
 *
 * The digits are random, not sequential, so a number cannot be used to infer how
 * many partners have joined or to guess a neighbour's. The first digit is never
 * zero, so the digits always read as an eleven-digit number. Uniqueness is
 * guaranteed by the unique index on the column, not by this class — see
 * {@see HasPartnerNumber} for the retry behaviour.
 */
final class PartnerNumber
{
    public const PREFIX = 'BJ';

    public const DIGITS = 11;

    /**
     * @throws RandomException
     */
    public static function generate(): string
    {
        $digits = (string) random_int(1, 9);

        for ($position = 1; $position < self::DIGITS; $position++) {
            $digits .= random_int(0, 9);
        }

        return self::PREFIX.$digits;
    }

    public static function matches(string $value): bool
    {
        return preg_match('/^'.self::PREFIX.'[1-9]\d{'.(self::DIGITS - 1).'}$/', $value) === 1;
    }
}
