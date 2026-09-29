<?php

namespace App\Domain\Payout\Enums;

/**
 * The account type step in the bank payout-method form (Bank -> District ->
 * Branch -> routing -> Account Holder -> Account Number -> Confirm ->
 * Account Type -> current password).
 */
enum BankAccountType: string
{
    case Savings = 'savings';
    case Current = 'current';

    public function label(): string
    {
        return match ($this) {
            self::Savings => 'Savings',
            self::Current => 'Current',
        };
    }
}
