<?php

namespace App\Domain\Access;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides how much of a module's data a viewer may see.
 *
 * One decision point, applied to pages, exports, reports, and API responses
 * alike. §31.3 is explicit that a user must not reach another user's data
 * "through URL changes, API requests, exports or modified parameters" — four
 * routes that only stay closed if they all consult the same answer.
 *
 * The rule is deliberately conservative: platform-wide visibility must be
 * granted by an explicit permission. Anything else sees its own rows, and a
 * guest sees nothing.
 */
class DataScopeResolver
{
    /**
     * Resolve the scope for a viewer against one module.
     */
    public function for(?Authenticatable $user, PermissionModule $module): DataScope
    {
        if ($user === null) {
            return DataScope::None;
        }

        if (! $user instanceof Authorizable) {
            // An authenticatable that cannot be asked about permissions gets
            // the safest answer, never the most generous one.
            return DataScope::Own;
        }

        $view = $module->value.'.'.PermissionAction::View->value;

        if (PermissionCatalogue::exists($view) && $user->can($view)) {
            return DataScope::All;
        }

        return DataScope::Own;
    }

    /**
     * Whether this viewer may export the module's data at all.
     *
     * Export is a separate permission from view, because a user who may read a
     * report on screen has not necessarily been trusted to carry the whole
     * dataset out of the system (§31.3, §32.2).
     */
    public function canExport(?Authenticatable $user, PermissionModule $module): bool
    {
        if (! $user instanceof Authorizable) {
            return false;
        }

        $export = $module->value.'.'.PermissionAction::Export->value;

        return PermissionCatalogue::exists($export) && $user->can($export);
    }

    /**
     * Whether this viewer may see columns marked sensitive within the module.
     *
     * Reading a settlement report is not the same as reading the bank details
     * inside it.
     */
    public function canSeeSensitive(?Authenticatable $user, PermissionModule $module): bool
    {
        if (! $user instanceof Authorizable) {
            return false;
        }

        $sensitive = $module->value.'.'.PermissionAction::ViewSensitiveData->value;

        return PermissionCatalogue::exists($sensitive) && $user->can($sensitive);
    }
}
