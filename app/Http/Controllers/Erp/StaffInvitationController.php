<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Account\Actions\AcceptStaffInvitation;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Accepting an invitation to join a business account (§8.1, D1, D23).
 *
 * Deliberately outside the commercial funnel gate. The person arriving here has
 * no account of their own — that is the point of being invited — so requiring an
 * activated business before they may accept would make it impossible to become
 * staff at all. Their own identity gate still applies, because it is global.
 *
 * The token identifies the invitation; it does not authorise the acceptance.
 * {@see AcceptStaffInvitation} checks that the signed-in person is the one it was
 * addressed to, so a forwarded link gets someone as far as the page and no
 * further.
 */
class StaffInvitationController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = AccountInvitation::query()
            ->with(['businessAccount', 'invitedBy'])
            ->where('token', $token)
            ->first();

        if ($invitation === null || ! $invitation->isLive()) {
            return Inertia::render('staff/invitation', [
                'invitation' => null,
                'reason' => __('This invitation is no longer valid. Ask for a new one.'),
            ]);
        }

        $user = $request->user();

        return Inertia::render('staff/invitation', [
            'invitation' => [
                'token' => $token,
                'account' => $invitation->businessAccount->name,
                'role' => $invitation->role->value,
                'roleLabel' => $invitation->role->label(),
                'invitedBy' => $invitation->invitedBy?->name,
                'email' => $invitation->email,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            // What the viewer needs to do next, worked out server-side: signing
            // in as the wrong person is the common case, and "accept" would just
            // fail for them without saying why.
            'viewer' => [
                'signedIn' => $user !== null,
                'matches' => $user !== null && $invitation->matches($user),
                'hasAccount' => $user?->accountMembership()->exists() ?? false,
            ],
            'reason' => null,
        ]);
    }

    /**
     * The signed-in person. The route is behind `auth`; null would mean the
     * middleware stack changed underneath us, not a real guest.
     */
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    public function accept(
        Request $request,
        string $token,
        AcceptStaffInvitation $accept,
    ): RedirectResponse {
        $invitation = AccountInvitation::query()->where('token', $token)->firstOrFail();

        try {
            $accept->handle($invitation, $this->actor($request));
        } catch (StaffLimitReached $exception) {
            return back()->withErrors(['invitation' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['invitation' => $exception->getMessage()]);
        }

        return redirect()->route('dashboard')
            ->with('success', __('You have joined :account.', [
                'account' => $invitation->businessAccount->name,
            ]));
    }
}
