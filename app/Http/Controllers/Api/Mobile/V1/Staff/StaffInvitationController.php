<?php

namespace App\Http\Controllers\Api\Mobile\V1\Staff;

use App\Domain\Account\Actions\AcceptStaffInvitation;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Accepting an invitation to join a business account (§8.1, D1, D23).
 *
 * Deliberately outside the activation gate. The person arriving here has no
 * account of their own — that is the point of being invited — so requiring an
 * activated business before they may accept would make it impossible to become
 * staff at all.
 *
 * The token identifies the invitation; it does not authorise the acceptance.
 * {@see AcceptStaffInvitation} checks that the signed-in person is the one it was
 * addressed to, so a forwarded token gets someone as far as reading it and no
 * further.
 */
class StaffInvitationController extends MobileController
{
    /**
     * Read an invitation
     *
     * What the invitation offers (the business, the role, who invited) and what this person
     * needs to do next: `viewer.matches` says whether it is addressed to them, `viewer.has_account`
     * whether they already belong to a business. `404` for a token that is unknown, used,
     * withdrawn or expired.
     *
     * @response array{invitation: array<string, mixed>, viewer: array{signed_in: bool, matches: bool, has_account: bool}}
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $invitation = $this->live($token);
        $user = $this->actor($request);

        return new JsonResponse([
            'invitation' => [
                'token' => $token,
                'account' => $invitation->businessAccount->name,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'invited_by' => $invitation->invitedBy?->name,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            // What the viewer needs to do next, worked out server-side: signing
            // in as the wrong person is the common case, and "accept" would just
            // fail for them without saying why.
            'viewer' => [
                'signed_in' => true,
                'matches' => $invitation->matches($user),
                'has_account' => $user->accountMembership()->exists(),
            ],
        ]);
    }

    /**
     * Accept an invitation
     *
     * Joins the business the invitation was addressed to. `404` for an unknown token; `422` on
     * `invitation` when it is not addressed to this person, is no longer open, they already
     * belong to a business, or the package's staff limit has been reached.
     *
     * @response array{message: string, account: string}
     */
    public function accept(Request $request, string $token, AcceptStaffInvitation $accept): JsonResponse
    {
        $invitation = AccountInvitation::query()->with('businessAccount')->where('token', $token)->firstOrFail();

        try {
            $accept->handle($invitation, $this->actor($request));
        } catch (StaffLimitReached|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return new JsonResponse([
            'message' => __('You have joined :account.', ['account' => $invitation->businessAccount->name]),
            'account' => $invitation->businessAccount->name,
        ]);
    }

    protected function live(string $token): AccountInvitation
    {
        $invitation = AccountInvitation::query()
            ->with(['businessAccount', 'invitedBy'])
            ->where('token', $token)
            ->first();

        abort_if($invitation === null || ! $invitation->isLive(), 404, __('This invitation is no longer valid. Ask for a new one.'));

        return $invitation;
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
