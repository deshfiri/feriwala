<?php

namespace App\Domain\Payout\Enums;

/**
 * The two actor kinds `payout_methods` holds methods for.
 *
 * A plain string column, not an Eloquent morph map — nothing here needs the
 * relation itself, only the pair to scope a query by (§31.3), the same
 * choice `App\Domain\Address\Enums\AddressOwnerType` already made for the
 * other two-owner-kind resource in this codebase.
 */
enum PayoutOwnerType: string
{
    case BusinessAccount = 'business_account';
    case Supplier = 'supplier';
}
