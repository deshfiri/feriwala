<?php

namespace App\Domain\Supplier\Exceptions;

use App\Support\Money\Money;
use RuntimeException;

/**
 * Why a Supplier withdrawal request or decision could not proceed (D25, P13-24).
 */
class SupplierWithdrawalRefused extends RuntimeException
{
    public static function supplierNotOperational(): self
    {
        return new self('Only an operational Supplier may request a withdrawal.');
    }

    public static function belowMinimum(Money $requested, Money $minimum): self
    {
        return new self(sprintf(
            'The minimum withdrawal is %s; %s was requested.',
            $minimum->format(),
            $requested->format(),
        ));
    }

    public static function aboveMaximum(Money $requested, Money $maximum): self
    {
        return new self(sprintf(
            'The maximum withdrawal is %s; %s was requested.',
            $maximum->format(),
            $requested->format(),
        ));
    }

    public static function payoutMethodNotUsable(): self
    {
        return new self('This payout method belongs to a different Supplier or is archived.');
    }

    public static function illegalTransition(string $reference, string $from): self
    {
        return new self("Withdrawal {$reference} is {$from} and cannot move that way.");
    }
}
