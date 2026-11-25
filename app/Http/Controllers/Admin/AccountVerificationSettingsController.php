<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Actions\ConfigureMobileVerificationRequirement;
use App\Domain\Account\Actions\ConfigureOnboardingSettings;
use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Account\PreferredDomainExtensions;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Kyc\KycSelfiePolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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
        protected KycSelfiePolicy $selfies,
        protected PreferredDomainExtensions $extensions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($this->canView($actor), 403);

        return Inertia::render('admin/account-verification-settings', [
            'settings' => [
                'mobile_verification_required' => $this->requirement->isRequired(),
                // D39: the selfie for everyone ('' leaves it to each KYC batch),
                // and the extensions a partner may choose a domain from.
                'kyc_selfie_mode' => $this->selfies->global()->value ?? '',
                'domain_extensions' => $this->extensions->enabled(),
            ],
            'selfie_modes' => array_map(
                fn (KycSelfieMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                KycSelfieMode::cases(),
            ),
            'can' => ['manage' => $this->canManage($actor)],
        ]);
    }

    public function update(Request $request, ConfigureOnboardingSettings $onboarding): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $request->validate([
            'mobile_verification_required' => ['required', 'boolean'],
            'kyc_selfie_mode' => ['sometimes', 'nullable', Rule::in(['', ...array_map(fn (KycSelfieMode $mode) => $mode->value, KycSelfieMode::cases())])],
            'domain_extensions' => ['sometimes', 'array', 'min:1', 'max:20'],
            'domain_extensions.*' => ['string', 'max:24'],
        ]);

        $this->configure->handle($actor, (bool) $validated['mobile_verification_required']);

        if (array_key_exists('kyc_selfie_mode', $validated)) {
            $mode = KycSelfieMode::tryFrom((string) $validated['kyc_selfie_mode']);

            if ($mode?->value !== $this->selfies->global()?->value) {
                $onboarding->selfie($actor, $mode);
            }
        }

        if (array_key_exists('domain_extensions', $validated)) {
            try {
                $onboarding->domainExtensions($actor, array_values($validated['domain_extensions']));
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['domain_extensions' => $exception->getMessage()]);
            }
        }

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
