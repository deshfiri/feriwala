<?php

namespace App\Domain\Access\Enums;

/**
 * How much of a dataset a viewer may see.
 *
 * §31.3 and §44 require that a user reaches only their own operational and
 * financial data, and that this holds through URL changes, API requests,
 * exports, and modified parameters. Expressing the answer as one of three
 * values — rather than scattered `if ($user->isAdmin())` checks — means the
 * decision is made once and applied identically to a page, an export, and an
 * API response.
 */
enum DataScope: string
{
    /** Platform staff with the relevant permission: every row. */
    case All = 'all';

    /** An ordinary account: only rows belonging to them. */
    case Own = 'own';

    /** No permission to view this at all: no rows, and no hint that any exist. */
    case None = 'none';

    public function isRestricted(): bool
    {
        return $this !== self::All;
    }
}
