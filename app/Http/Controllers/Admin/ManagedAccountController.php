<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Actions\CreateManagedAccount;
use App\Domain\Account\Actions\ManagePasswordSetup;
use App\Domain\Account\Actions\VerifyContactManually;
use App\Domain\Account\Models\BusinessAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Localization\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Staff opening a Client/Partner account on someone's behalf, and managing its
 * password-setup invitation.
 *
 * The owner sets their own password through an expiring, single-use link;
 * nothing here accepts, shows or stores one.
 */
class ManagedAccountController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', BusinessAccount::class);

        return Inertia::render('admin/accounts/create', [
            'countries' => app(Countries::class)->options(),
            'default_country' => app(Countries::class)->default(),
        ]);
    }

    public function store(Request $request, CreateManagedAccount $create): RedirectResponse
    {
        Gate::authorize('create', BusinessAccount::class);

        $account = $create->handle($this->actor($request), $request->only([
            'name', 'business_name', 'email', 'mobile', 'country', 'referral_code', 'reason',
        ]));

        return to_route('admin.accounts.show', $account)
            ->with('success', __('managed_accounts.created'));
    }

    public function sendSetupLink(Request $request, BusinessAccount $account, ManagePasswordSetup $setup): RedirectResponse
    {
        Gate::authorize('manageIdentity', $account);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->guard(fn () => $setup->issue($account->owner()->firstOrFail(), ManagePasswordSetup::USERS, $this->actor($request), $validated['reason']));

        return back()->with('success', __('managed_accounts.link_sent'));
    }

    public function revokeSetupLink(Request $request, BusinessAccount $account, ManagePasswordSetup $setup): RedirectResponse
    {
        Gate::authorize('manageIdentity', $account);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->guard(fn () => $setup->revoke($account->owner()->firstOrFail(), ManagePasswordSetup::USERS, $this->actor($request), $validated['reason']));

        return back()->with('success', __('managed_accounts.link_revoked'));
    }

    public function verifyContact(Request $request, BusinessAccount $account, VerifyContactManually $verify): RedirectResponse
    {
        Gate::authorize('verifyContact', $account);

        $validated = $request->validate([
            'channel' => ['required', Rule::in([VerifyContactManually::EMAIL, VerifyContactManually::MOBILE])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->guard(fn () => $verify->handle($this->actor($request), $account->owner()->firstOrFail(), $validated['channel'], $validated['reason']));

        return back()->with('success', __('managed_accounts.verified'));
    }

    protected function guard(callable $change): void
    {
        try {
            $change();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
