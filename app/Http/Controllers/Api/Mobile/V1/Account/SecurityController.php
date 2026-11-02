<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Account\Actions\EndAuthenticatedSessions;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Account\AuthenticatedSessionResource;
use App\Models\User;
use App\Support\Security\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Laravel\Fortify\Features;

/**
 * Security settings: the second factor's state and the person's browser sessions.
 *
 * The app's own sign-ins are bearer tokens, listed and revoked under
 * `/auth/devices`. The sessions here are the person's sign-ins to the web ERP.
 * Passkeys (WebAuthn) are a web-only feature and are not offered here.
 */
class SecurityController extends MobileController
{
    /**
     * How many sessions the list shows (the same cap as the web screen).
     */
    public const SESSION_HISTORY_LIMIT = 15;

    /**
     * Security overview
     *
     * Whether two-factor authentication is on, whether setup is half-done
     * (`two_factor_pending_confirmation`), whether a role makes it mandatory, and
     * the password policy string.
     *
     * @response array{data: array{two_factor_enabled: bool, two_factor_pending_confirmation: bool, two_factor_requires_confirmation: bool, two_factor_required: bool, password_rules: string}}
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => [
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'two_factor_pending_confirmation' => $user->two_factor_secret !== null && ! $user->hasEnabledTwoFactorAuthentication(),
            'two_factor_requires_confirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            'two_factor_required' => $user->requiresTwoFactorAuthentication(),
            'password_rules' => PasswordPolicy::hint(),
        ]]);
    }

    /**
     * My web sessions
     *
     * The person's web ERP sign-ins, live and finished, most recently active
     * first (at most 15). `ended: false` rows can be revoked.
     */
    public function sessions(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AuthenticatedSessionResource::collection(
            $user->authenticatedSessions()
                ->orderByDesc('last_active_at')
                ->orderByDesc('id')
                ->limit(self::SESSION_HISTORY_LIMIT)
                ->get(),
        );
    }

    /**
     * End one web session
     *
     * Signs the web browser out. The session is found through the caller's own
     * sessions, so another person's id is a `404`.
     */
    public function destroySession(Request $request, string $session, EndAuthenticatedSessions $endSessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $target = $user->authenticatedSessions()->wherePublicId($session)->firstOrFail();

        $endSessions->one($user, $target);

        return response()->noContent();
    }

    /**
     * End every web session
     *
     * Signs the person out of the web ERP everywhere. Bearer tokens (this app and
     * other phones) are untouched; manage those under `/auth/devices`.
     */
    public function purgeSessions(Request $request, EndAuthenticatedSessions $endSessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $endSessions->all($user);

        return response()->noContent();
    }
}
