<?php

namespace App\Providers;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Policies\AccountMembershipPolicy;
use App\Domain\Account\Policies\BusinessAccountPolicy;
use App\Domain\Account\Policies\UserPolicy;
use App\Domain\Catalog\Policies\CatalogModelPolicy;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Policies\KycDocumentPolicy;
use App\Domain\Kyc\Policies\KycDocumentTypePolicy;
use App\Domain\Kyc\Policies\KycSubmissionPolicy;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Policies\PackagePolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policy registration and the Super Admin override.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(KycSubmission::class, KycSubmissionPolicy::class);
        Gate::policy(KycDocument::class, KycDocumentPolicy::class);

        // Configuring what applicants are asked for is `kyc.manage_settings`,
        // not `kyc.approve` — shaping the catalogue is not reviewing a case.
        Gate::policy(KycDocumentType::class, KycDocumentTypePolicy::class);
        Gate::policy(BusinessAccount::class, BusinessAccountPolicy::class);

        // Shaping what Feriwala sells is its own permission set (§8.1):
        // writing package copy and withdrawing a plan are different jobs.
        Gate::policy(Package::class, PackagePolicy::class);

        // Both staff management and invitations answer to the membership policy:
        // the question in each case is what the actor's own membership permits.
        Gate::policy(AccountMembership::class, AccountMembershipPolicy::class);
        Gate::policy(AccountInvitation::class, AccountMembershipPolicy::class);

        /*
         * Whether a person may sign in at all is an identity question, so it has
         * a policy of its own rather than being folded into the business
         * account's (§6, D23). Registered explicitly because the policy does not
         * live where auto-discovery looks.
         */
        Gate::policy(User::class, UserPolicy::class);

        /*
         * Every catalogue model answers to one policy (§12), so a check phrased
         * as a Gate ability, a permission name, or a controller call cannot
         * reach a different answer.
         */
        foreach (CatalogPolicy::MODELS as $model) {
            Gate::policy($model, CatalogModelPolicy::class);
        }

        /*
         * Super Admin passes every check without holding permission rows, so
         * the grant cannot drift out of step with the catalogue as modules are
         * added.
         *
         * Returning null rather than false when the role is absent is essential:
         * false here would short-circuit every other check and deny everyone.
         *
         * It does not put the account owner at risk. The owner's protection is
         * in {@see \App\Domain\Account\Actions\ManageStaff}, which refuses to
         * remove or demote them whatever the caller is permitted to do — an
         * invariant of the account rather than a grant that can be overridden.
         */
        /*
         * One ordered hook instead of three independent ones, because the order
         * is the rule. `config('permission.register_permission_check_method')`
         * is off so the permission package does not register its own check
         * ahead of this one.
         */
        Gate::before(function (User $user, string $ability, array &$arguments = []) {
            // The permission package's guard convention: `can('x', 'web')`.
            if (is_string($arguments[0] ?? null) && ! class_exists($arguments[0])) {
                $guard = array_shift($arguments);
            }

            /*
             * 1. The catalogue, refused to anybody who trades on the platform
             *    (§12). First, because either check below would otherwise
             *    answer yes before any policy is asked: a partner who has been
             *    handed a catalogue permission or a platform role, by mistake or
             *    on purpose, still does not author the catalogue they sell from.
             */
            if (CatalogPolicy::isCatalogueAbility($ability, $arguments) && CatalogPolicy::isBusinessIdentity($user)) {
                return false;
            }

            // 2. A permission this person holds — the package's own check, unchanged.
            if ($user->checkPermissionTo($ability, $guard ?? null)) {
                return true;
            }

            // 3. Super Admin, for everything else.
            return $user->hasRole(PlatformRole::SuperAdmin->value) ? true : null;
        });
    }
}
