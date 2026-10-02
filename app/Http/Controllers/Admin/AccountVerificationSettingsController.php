<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Actions\ConfigureMobileVerificationRequirement;
use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Account\MobileVerificationRequirement;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Whether mobile number verification is a required onboarding step (§5.1).
 *
 * On by default. Switching it off does not just stop sending the code -- see
 * {@see SkipMobileVerificationIfNotRequired} --
 * it removes the requirement itself, so nobody registering while it is off
 * is ever blocked by a step they have no way to complete.
 */
class AccountVerificationSettingsController extends Controller
{
    public function __construct(
        protected MobileVerificationRequirement $requirement,
        protected ConfigureMobileVerificationRequirement $configure,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($this->canView($actor), 403);

        return Inertia::render('admin/account-verification-settings', [
            'settings' => [
                'mobile_verification_required' => $this->requirement->isRequired(),
            ],
            'can' => ['manage' => $this->canManage($actor)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $request->validate([
            'mobile_verification_required' => ['required', 'boolean'],
        ]);

        $this->configure->handle($actor, (bool) $validated['mobile_verification_required']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account_verification_settings.saved')]);

        return back();
    }

    protected function canView(User $actor): bool
    {
        return $actor->can(PermissionCatalogue::name(PermissionModule::Account, PermissionAction::View));
    }

    protected function canManage(User $actor): bool
    {
        return $actor->can(PermissionCatalogue::name(PermissionModule::Account, PermissionAction::ManageSettings));
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
