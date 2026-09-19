<?php

namespace App\Domain\Referral\Enums;

/**
 * How an account came to have its direct referrer (§25.1, D24).
 *
 * Registration is the ordinary path: the owner typed a code. Backfill carried
 * the registrations that happened before the hierarchy existed. Staff is a
 * person attaching or correcting one before it was locked, with a reason.
 */
enum ReferralAttachment: string
{
    case Registration = 'registration';
    case Backfill = 'backfill';
    case Staff = 'staff';
}
