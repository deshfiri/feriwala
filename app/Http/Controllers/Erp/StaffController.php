<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Account\Actions\InviteStaff;
use App\Domain\Account\Actions\ManageStaff;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\StaffAllowance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\InviteStaffRequest;
use App\Http\Requests\Account\UpdateStaffRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Staff of the signed-in person's own account (§8.1, D1).
 *
 * The account is never a route parameter. It is resolved from the membership of
 * whoever is signed in, so there is nothing in the URL to tamper with and no way
 * to ask about somebody else's business (§31.3). That is why the routes are
 * `settings/staff` rather than `settings/{account}/staff`, and why the previous
 * `{current_team}` prefix is gone rather than renamed.
 *
 * Staff are addressed by the person's public id, and the membership is looked up
 * within the actor's own account. A public id belonging to someone in another
 * business resolves to a user and then to no membership here — a 404, without a
 * policy ever having to compensate for a binding that reached too far.
 */
class StaffController extends Controller
{
    public function __construct(
        protected StaffAllowance $allowance,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->account($request);

        Gate::authorize('viewAny', [AccountMembership::class, $account]);

        $account->load('memberships.user');

        $actor = $this->actor($request);

        return Inertia::render('settings/staff', [
            'staff' => $account->memberships
                ->sortBy(fn (AccountMembership $membership) => sprintf(
                    '%d%s',
                    $membership->role === AccountRole::Owner ? 0 : 1,
                    mb_strtolower($membership->user->name),
                ))
                ->values()
                ->map(fn (AccountMembership $membership) => [
                    'id' => $membership->user->public_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->value,
                    'roleLabel' => $membership->role->label(),
                    'isOwner' => $membership->role === AccountRole::Owner,
                    'isYou' => $membership->user_id === $actor->id,
                    'canManage' => $actor->can('remove', $membership),
                    'joinedAt' => $membership->created_at?->toIso8601String(),
                ]),

            'invitations' => AccountInvitation::query()
                ->where('business_account_id', $account->id)
                ->live()
                ->with('invitedBy')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AccountInvitation $invitation) => [
                    'id' => $invitation->public_id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'roleLabel' => $invitation->role->label(),
                    'invitedBy' => $invitation->invitedBy?->name,
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                ]),

            'allowance' => [
                'limit' => $this->allowance->limit($account),
                'used' => $this->allowance->used($account),
                'remaining' => $this->allowance->remaining($account),
            ],

            'roles' => collect(AccountRole::invitable())
                ->map(fn (AccountRole $role) => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ]),

            'can' => [
                'invite' => $actor->can('invite', [AccountMembership::class, $account]),
            ],
        ]);
    }

    public function invite(InviteStaffRequest $request, InviteStaff $inviteStaff): RedirectResponse
    {
        $account = $this->account($request);

        Gate::authorize('invite', [AccountMembership::class, $account]);

        try {
            $inviteStaff->handle(
                account: $account,
                invitedBy: $this->actor($request),
                email: $request->string('email')->toString(),
                role: AccountRole::from($request->string('role')->toString()),
                mobile: $request->input('mobile'),
            );
        } catch (StaffLimitReached $exception) {
            // A package limit is a rejected form, not a 500. The message carries
            // the numbers, so a manager can tell "buy a bigger package" apart
            // from "chase the two people who have not accepted yet".
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return back()->with('success', __('Invitation sent.'));
    }

    public function revokeInvitation(
        Request $request,
        AccountInvitation $invitation,
        ManageStaff $manageStaff,
    ): RedirectResponse {
        Gate::authorize('revokeInvitation', $invitation);

        try {
            $manageStaff->revoke($invitation, $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return back()->with('success', __('Invitation withdrawn.'));
    }

    public function updateRole(
        UpdateStaffRoleRequest $request,
        User $staff,
        ManageStaff $manageStaff,
    ): RedirectResponse {
        $membership = $this->membership($request, $staff);

        Gate::authorize('changeRole', $membership);

        try {
            $manageStaff->changeRole(
                $membership,
                AccountRole::from($request->string('role')->toString()),
                $this->actor($request),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['role' => $exception->getMessage()]);
        }

        return back()->with('success', __('Permissions updated.'));
    }

    public function remove(
        Request $request,
        User $staff,
        ManageStaff $manageStaff,
    ): RedirectResponse {
        $membership = $this->membership($request, $staff);

        Gate::authorize('remove', $membership);

        try {
            $manageStaff->remove($membership, $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['staff' => $exception->getMessage()]);
        }

        return back()->with('success', __('Staff member removed.'));
    }

    /**
     * The signed-in person. Every route here is behind `auth`, so null means
     * the middleware stack changed underneath us rather than a real guest.
     */
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * The signed-in person's own account, or a 403.
     */
    protected function account(Request $request): BusinessAccount
    {
        $account = $request->user()?->businessAccount;

        abort_if($account === null, 403);

        return $account;
    }

    /**
     * The named person's membership **of the actor's account**.
     *
     * Scoped rather than bound: binding the membership directly would accept an
     * identifier from any account and lean on the policy to notice. This way the
     * query cannot reach outside the caller's own business at all (§31.3).
     */
    protected function membership(Request $request, User $staff): AccountMembership
    {
        $membership = $this->account($request)
            ->memberships()
            ->where('user_id', $staff->id)
            ->first();

        abort_if($membership === null, 404);

        return $membership;
    }
}
