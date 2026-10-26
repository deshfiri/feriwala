<?php

namespace App\Domain\Order\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;

/**
 * Who may see a return, exchange or damage case (D35, §31.3).
 *
 * Two audiences, kept apart exactly as {@see OrderPolicy} keeps them:
 *
 *   - **The Partner whose order it is** sees their own cases and nobody else's,
 *     decided from the signed-in person's own business account and never from
 *     anything a request names. This is query scoping by account, not a
 *     permission: a Partner holds no `return.*` ability at all.
 *   - **Platform staff** see cases through the `return.*`, `exchange.*` and
 *     `damage.*` permissions their role holds.
 *
 * The ordered `Gate::before` refuses every one of those abilities to a business
 * identity, so a Partner handed `return.view` by mistake still reads only their
 * own account's cases, through the first path.
 */
class ReturnCasePolicy
{
    /** Platform case abilities start with one of these. */
    public const ABILITY_PREFIXES = ['return.', 'exchange.', 'damage.'];

    public static function isCaseAdministrationAbility(string $ability): bool
    {
        foreach (self::ABILITY_PREFIXES as $prefix) {
            if (str_starts_with($ability, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function viewAny(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::View));
    }

    public function view(User $user, OrderReturn $case): bool
    {
        return $this->ownedBy($user, $case)
            || $user->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::View));
    }

    /**
     * Whether this is the signed-in Partner's own case.
     */
    public function ownedBy(User $user, OrderReturn $case): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $account->id === $case->business_account_id;
    }
}
