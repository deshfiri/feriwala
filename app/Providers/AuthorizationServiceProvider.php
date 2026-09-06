<?php

namespace App\Providers;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Policies\AccountMembershipPolicy;
use App\Domain\Account\Policies\BusinessAccountPolicy;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Policies\KycDocumentPolicy;
use App\Domain\Kyc\Policies\KycDocumentTypePolicy;
use App\Domain\Kyc\Policies\KycSubmissionPolicy;
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

        // Both staff management and invitations answer to the membership policy:
        // the question in each case is what the actor's own membership permits.
        Gate::policy(AccountMembership::class, AccountMembershipPolicy::class);
        Gate::policy(AccountInvitation::class, AccountMembershipPolicy::class);

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
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole(PlatformRole::SuperAdmin->value) ? true : null;
        });
    }
}
