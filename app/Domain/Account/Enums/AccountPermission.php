<?php

namespace App\Domain\Account\Enums;

use App\Domain\Access\PermissionCatalogue;

/**
 * What a person may do inside their own business account (§8.1, §32).
 *
 * Distinct from {@see PermissionCatalogue}, which is the
 * platform-wide catalogue Feriwala's own staff hold roles in. This one never
 * leaves the account: holding `staff:invite` here says nothing about any other
 * business, and everything is resolved through `business_account_members`.
 *
 * The vocabulary is Account, Staff and Permissions. There is no team.
 */
enum AccountPermission: string
{
    /** Rename the business, change its settings. */
    case UpdateAccount = 'account:update';

    case InviteStaff = 'staff:invite';
    case UpdateStaff = 'staff:update';
    case RemoveStaff = 'staff:remove';

    case RevokeInvitation = 'invitation:revoke';
}
