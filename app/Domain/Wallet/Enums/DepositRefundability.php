<?php

namespace App\Domain\Wallet\Enums;

/**
 * How much of a deposit comes back (§24.4).
 *
 * §24.4 lists six things an administrator may configure, and they are not six
 * values of one field — three of them answer "how much is refundable" and three
 * are separate conditions on the same money. This enum is the first question
 * only; the others are their own flags, because an account can have a fully
 * refundable deposit that is *also* reserved until cancellation and *also*
 * spendable on services, and folding them together would make two of those
 * unsayable.
 */
enum DepositRefundability: string
{
    case Full = 'full';
    case Partial = 'partial';
    case None = 'none';

    /**
     * Whether any of it comes back at all.
     */
    public function refundsAnything(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether a percentage has to be configured beside it.
     */
    public function needsPercentage(): bool
    {
        return $this === self::Partial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Fully refundable',
            self::Partial => 'Partially refundable',
            self::None => 'Non-refundable',
        };
    }
}
