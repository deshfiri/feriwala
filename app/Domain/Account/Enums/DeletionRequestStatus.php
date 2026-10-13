<?php

namespace App\Domain\Account\Enums;

/**
 * Where a request to delete an account stands.
 *
 * Approved means the account has been erased: the request stays, pointing at the
 * anonymous tombstone the login became.
 */
enum DeletionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
