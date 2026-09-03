<?php

namespace App\Providers;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Policies\UserPolicy;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Policies\KycDocumentPolicy;
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
        Gate::policy(User::class, UserPolicy::class);

        /*
         * Super Admin passes every check without holding permission rows, so
         * the grant cannot drift out of step with the catalogue as modules are
         * added.
         *
         * Returning null rather than false when the role is absent is essential:
         * false here would short-circuit every other check and deny everyone.
         */
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole(PlatformRole::SuperAdmin->value) ? true : null;
        });
    }
}
