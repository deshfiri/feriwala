<?php

namespace App\Domain\Wallet\Enums;

/**
 * Which way money moved (§23.2).
 *
 * Both amounts are stored positive and told apart by the column they land in;
 * this is how a caller says which column it means.
 */
enum LedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function isCredit(): bool
    {
        return $this === self::Credit;
    }

    public function label(): string
    {
        return $this === self::Credit ? 'In' : 'Out';
    }
}
