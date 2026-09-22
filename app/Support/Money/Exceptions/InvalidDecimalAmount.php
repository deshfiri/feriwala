<?php

namespace App\Support\Money\Exceptions;

use InvalidArgumentException;

/**
 * A human-entered amount failed the exact decimal boundary (§36.1).
 *
 * Thrown by {@see \App\Support\Money\DecimalAmount}, never by
 * {@see \App\Support\Money\Money} itself — this is the stricter contract the
 * human-input boundary holds callers to, on top of what `Money::fromDecimal()`
 * already tolerates (which rounds a third decimal place rather than refusing
 * it, correct for internal callers that compute a fraction, wrong for a
 * person typing a price).
 */
class InvalidDecimalAmount extends InvalidArgumentException
{
    public static function malformed(string $input): self
    {
        return new self(sprintf('[%s] is not a valid amount.', $input));
    }

    public static function excessivePrecision(string $input, int $scale): self
    {
        return new self(sprintf('[%s] has more than %d decimal place(s).', $input, $scale));
    }

    public static function negativeNotAllowed(string $input): self
    {
        return new self(sprintf('[%s] cannot be negative here.', $input));
    }

    public static function overflow(string $input): self
    {
        return new self(sprintf('[%s] is larger than this field allows.', $input));
    }
}
