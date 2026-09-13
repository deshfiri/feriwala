<?php

namespace App\Domain\Catalog\Enums;

/**
 * Whether a product is further restricted to named business accounts
 * (§11.1 "User eligibility").
 *
 * A restriction on top of package eligibility, never a way around it: an
 * account listed here still needs a package the product is offered to. "User"
 * in §11.1 is the business account — the thing that buys and sells — because a
 * staff member acts for the account and holds no eligibility of their own (D1).
 */
enum AccountScope: string
{
    case AnyAccount = 'any';
    case SelectedAccounts = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::AnyAccount => 'Any eligible account',
            self::SelectedAccounts => 'Only the listed accounts',
        };
    }
}
