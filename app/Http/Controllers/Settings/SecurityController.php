<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Account\Actions\EndAuthenticatedSessions;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Support\Security\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * How many sessions the screen lists.
     *
     * Enough to cover "everywhere I am signed in" plus the recent past. Beyond
     * that the answer is a report, not a settings panel.
     */
    public const SESSION_HISTORY_LIMIT = 15;

    public function __construct(
        protected EndAuthenticatedSessions $endSessions,
    ) {}

    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => PasswordPolicy::hint(),
            'sessions' => $this->sessions($request),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }

    /**
     * End one other session (§6).
     */
    public function destroySession(Request $request, string $session): RedirectResponse
    {
        $user = $request->user();

        /*
         * Found through the person's own relation, never by looking the id up
         * globally and checking afterwards. §31.3 is a query concern: there is
         * no row here that belongs to somebody else, so there is nothing for a
         * changed parameter to reach.
         */
        $target = $user->authenticatedSessions()
            ->wherePublicId($session)
            ->firstOrFail();

        if ($target->session_key === AuthenticatedSession::keyFor($request->session()->getId())) {
            // Ending the session you are asking from is signing out, and doing
            // it here would leave the person on a page that no longer has them.
            return back()->withErrors([
                'session' => __('Use sign out to end the session you are using now.'),
            ]);
        }

        $this->endSessions->one($user, $target);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('That device has been signed out.')]);

        return back();
    }

    /**
     * End every session except this one (§6).
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $ended = $this->endSessions->exceptCurrent(
            $request->user(),
            $request->session()->getId(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('Signed out of :count other device|Signed out of :count other devices', $ended, ['count' => $ended]),
        ]);

        return back();
    }

    /**
     * This person's sessions, newest activity first.
     *
     * Live and finished together, because the history is half the point: "was
     * that me last Tuesday?" is a question about a session that has ended.
     * Capped, because the screen answers a question rather than being an
     * archive.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sessions(Request $request): array
    {
        $current = AuthenticatedSession::keyFor($request->session()->getId());

        return $request->user()
            ->authenticatedSessions()
            ->orderByDesc('last_active_at')
            ->limit(self::SESSION_HISTORY_LIMIT)
            ->get()
            ->map(fn (AuthenticatedSession $session) => [
                'id' => $session->public_id,
                'device' => $session->device_label,

                // The identifier is never sent to a browser. The address and the
                // device are what identify a session to the person reading.
                'ip_address' => $session->ip_address,
                'last_active_diff' => $session->last_active_at->diffForHumans(),
                'last_active_iso' => $session->last_active_at->toIso8601String(),
                'signed_in_iso' => $session->created_at?->toIso8601String(),
                'is_current' => $session->session_key === $current,
                'ended' => $session->hasEnded(),
                'ended_reason' => $session->ended_reason,
            ])
            ->all();
    }
}
