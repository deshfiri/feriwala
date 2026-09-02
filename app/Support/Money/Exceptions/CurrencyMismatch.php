<?php

namespace App\Support\Money\Exceptions;

use App\Support\Money\Currency;
use InvalidArgumentException;

class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(sprintf(
            'Cannot operate on %s and %s in the same expression. Money of different currencies must be converted explicitly.',
            $left->value,
            $right->value,
        ));
    }
}
