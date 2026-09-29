<?php

namespace App\Domain\Withdrawal\Exceptions;

use App\Support\Money\Money;
use RuntimeException;

/**
 * A Client/Partner withdrawal could not proceed as requested — always
 * carries a reason the account or a staff member can act on.
 */
class AccountWithdrawalRefused extends RuntimeException
{
    public static function accountNotActive(): self
    {
        return new self('This account is not active.');
    }

    public static function kycReverificationOutstanding(string $reason): self
    {
        return new self($reason);
    }

    public static function payoutMethodNotUsable(): self
    {
        return new self('That payout method cannot be used for this withdrawal.');
    }

    public static function belowMinimum(Money $amount, Money $minimum): self
    {
        return new self("The minimum withdrawal is {$minimum->toDecimal()} {$minimum->currency->value}.");
    }

    public static function aboveMaximum(Money $amount, Money $maximum): self
    {
        return new self("The maximum withdrawal is {$maximum->toDecimal()} {$maximum->currency->value}.");
    }

    public static function insufficientBalance(Money $amount, Money $available): self
    {
        return new self("Only {$available->toDecimal()} {$available->currency->value} is available for withdrawal.");
    }

    public static function illegalTransition(string $reference, string $currentStatusLabel): self
    {
        return new self("Withdrawal {$reference} is {$currentStatusLabel} and cannot move that way.");
    }
}
