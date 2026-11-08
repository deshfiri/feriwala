<?php

namespace App\Http\Controllers\Api\Mobile\V1\Staff;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Actions\InviteStaff;
use App\Domain\Account\Actions\ManageStaff;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\StaffAllowance;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Account\InviteStaffRequest;
use App\Http\Requests\Account\UpdateStaffRoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff of the signed-in person's own account (§8.1, D1).
 *
 * The account is never a route parameter. It is resolved from the membership of
 * whoever is signed in, so there is nothing in the URL to tamper with and no way
 * to ask about somebody else's business (§31.3).
 *
 * Staff and invitations are addressed by public id, and both are looked up
 * **within the actor's own account**: an id belonging to another business is a
 * `404`, without a policy ever having to compensate for a lookup that reached
 * too far.
 */
class StaffController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected StaffAllowance $allowance,
    ) {}

    /**
     * List staff
     *
     * The account's people (owner first), the invitations still open, the package's staff
     * allowance, the roles that can be given and whether this person may invite. `403` without
     * the right to see staff or when the package has no staff facility.
     *
     * @response array{staff: list<array<string, mixed>>, invitations: list<array<string, mixed>>, allowance: array{limit: int|null, used: int, remaining: int|null}, roles: list<array{value: string, label: string}>, can: array{invite: bool}}
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $actor = $this->actor($request);

        Gate::authorize('viewAny', [AccountMembership::class, $account]);

        $account->load('memberships.user');

        return new JsonResponse([
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
                    'role_label' => $membership->role->label(),
                    'is_owner' => $membership->role === AccountRole::Owner,
                    'is_you' => $membership->user_id === $actor->id,
                    'can_manage' => $actor->can('remove', $membership),
                    'joined_at' => $membership->created_at?->toIso8601String(),
                ])
                ->all(),

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
                    'role_label' => $invitation->role->label(),
                    'invited_by' => $invitation->invitedBy?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ])
                ->all(),

            'allowance' => [
                'limit' => $this->allowance->limit($account),
                'used' => $this->allowance->used($account),
                'remaining' => $this->allowance->remaining($account),
            ],

            'roles' => collect(AccountRole::invitable())
                ->map(fn (AccountRole $role) => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])
                ->all(),

            'can' => [
                'invite' => $actor->can('invite', [AccountMembership::class, $account]),
            ],
        ]);
    }

    /**
     * Invite staff
     *
     * Invites `email` as `manager` or `staff`, optionally also requiring a verified `mobile`.
     * Answers `201`. `403` without the right to invite; `422` when the package's staff limit is
     * reached or the person cannot be invited (the message says why).
     *
     * @response 201 array{message: string}
     */
    public function invite(InviteStaffRequest $request, InviteStaff $inviteStaff): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('invite', [AccountMembership::class, $account]);

        try {
            $inviteStaff->handle(
                account: $account,
                invitedBy: $this->actor($request),
                email: $request->string('email')->toString(),
                role: AccountRole::from($request->string('role')->toString()),
                mobile: $request->input('mobile'),
            );
        } catch (StaffLimitReached|InvalidArgumentException $exception) {
            // A package limit is a rejected request, not a 500. The message carries
            // the numbers, so a manager can tell "buy a bigger package" apart
            // from "chase the two people who have not accepted yet".
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return new JsonResponse(['message' => __('Invitation sent.')], 201);
    }

    /**
     * Withdraw an invitation
     *
     * Withdraws an open invitation of this account. `404` for another account's invitation;
     * `403` without the right; `422` when it is no longer open.
     *
     * @response array{message: string}
     */
    public function revokeInvitation(Request $request, string $invitation, ManageStaff $manageStaff): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        /** @var AccountInvitation $found */
        $found = AccountInvitation::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $invitation)
            ->firstOrFail();

        Gate::authorize('revokeInvitation', $found);

        try {
            $manageStaff->revoke($found, $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return new JsonResponse(['message' => __('Invitation withdrawn.')]);
    }

    /**
     * Change a role
     *
     * Gives a member of this account the role `manager` or `staff`. `404` for someone who is not
     * on this account; `403` without the right; `422` when the change is refused.
     *
     * @response array{message: string}
     */
    public function updateRole(UpdateStaffRoleRequest $request, string $staff, ManageStaff $manageStaff): JsonResponse
    {
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

        return new JsonResponse(['message' => __('Permissions updated.')]);
    }

    /**
     * Remove a member
     *
     * Removes a member from this account. `404` for someone who is not on this account; `403`
     * without the right (the owner cannot be removed); `422` when the removal is refused.
     *
     * @response array{message: string}
     */
    public function remove(Request $request, string $staff, ManageStaff $manageStaff): JsonResponse
    {
        $membership = $this->membership($request, $staff);

        Gate::authorize('remove', $membership);

        try {
            $manageStaff->remove($membership, $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['staff' => $exception->getMessage()]);
        }

        return new JsonResponse(['message' => __('Staff member removed.')]);
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * The named person's membership **of the actor's account**.
     *
     * Scoped rather than bound: the query cannot reach outside the caller's own
     * business at all (§31.3).
     */
    protected function membership(Request $request, string $publicId): AccountMembership
    {
        $account = $this->businessAccountFor($request);

        /** @var AccountMembership|null $membership */
        $membership = $account->memberships()
            ->whereHas('user', fn ($query) => $query->where('public_id', $publicId))
            ->first();

        abort_if($membership === null, 404);

        return $membership;
    }
}
