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

    /**
     * A shop order paid to the Partner's own gateway is waiting for Banij's share
     * to be taken from this Wallet (D36). Says which order and how much — never who bought it.
     */
    public static function receivableCollectionPending(string $orderReference, Money $amount): self
    {
        return new self("Order {$orderReference} is waiting for {$amount->toDecimal()} {$amount->currency->value} to be collected from your wallet before it can go ahead. New withdrawals resume once it is collected or its deadline has passed.");
    }

    public static function illegalTransition(string $reference, string $currentStatusLabel): self
    {
        return new self("Withdrawal {$reference} is {$currentStatusLabel} and cannot move that way.");
    }
}
