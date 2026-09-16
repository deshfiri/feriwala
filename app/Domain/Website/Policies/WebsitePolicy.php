<?php

namespace App\Domain\Website\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Website\Models\Website;
use App\Models\User;

/**
 * Who may see and run a dedicated website (§16.3, §31.3).
 *
 * Two audiences, kept apart the way the order policy keeps them apart:
 *
 *   - **The account that owns it** manages its own storefront and nobody
 *     else's, decided from the signed-in person's own business account and
 *     never from anything a request names.
 *   - **Platform staff** administer every website through the website
 *     permissions their role holds.
 *
 * Inside an account it is narrower still. Running a website spends the
 * account's money — a setup charge, a renewal — and changes what its customers
 * see, so it sits behind the account permission that covers the business
 * itself rather than being open to every staff member who can sign in.
 *
 * A partner never holds platform website administration, whatever has been
 * handed to them: the ordered `Gate::before` refuses `website.*` to a business
 * identity, exactly as it refuses the catalogue and platform order
 * administration.
 */
class WebsitePolicy
{
    /** Platform website abilities start with this. */
    public const ABILITY_PREFIX = 'website.';

    public static function isWebsiteAdministrationAbility(string $ability): bool
    {
        return str_starts_with($ability, self::ABILITY_PREFIX);
    }

    /**
     * The platform's list of every partner website.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Website, PermissionAction::View));
    }

    public function view(User $user, Website $website): bool
    {
        return $this->belongsToAccountOf($user, $website)
            || $user->can(PermissionCatalogue::name(PermissionModule::Website, PermissionAction::View));
    }

    /**
     * Ask for a new website. The account's own decision, and a paid one.
     */
    public function create(User $user): bool
    {
        return $this->runsTheBusiness($user);
    }

    /**
     * Change what the storefront is and what it shows (§16.3).
     */
    public function manage(User $user, Website $website): bool
    {
        return ($this->belongsToAccountOf($user, $website) && $this->runsTheBusiness($user))
            || $this->administer($user, $website);
    }

    /**
     * Spend the account's money on it — a setup charge, a renewal (§24).
     *
     * Deliberately not open to platform staff: Feriwala may suspend a website,
     * but taking money out of a partner's wallet is the partner's own act.
     */
    public function pay(User $user, Website $website): bool
    {
        return $this->belongsToAccountOf($user, $website) && $this->runsTheBusiness($user);
    }

    /**
     * Move a website through §16.4 by hand, or record what was provisioned.
     */
    public function administer(User $user, Website $website): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Website, PermissionAction::Edit));
    }

    protected function belongsToAccountOf(User $user, Website $website): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $account->id === $website->business_account_id;
    }

    /**
     * Whether this person speaks for the business rather than works in it.
     */
    protected function runsTheBusiness(User $user): bool
    {
        return $user->businessAccount !== null
            && ($user->accountRole()?->hasPermission(AccountPermission::UpdateAccount) ?? false);
    }
}
