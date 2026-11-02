<?php

namespace App\Http\Controllers\Api\Mobile\V1\Auth;

use App\Domain\Account\Actions\IssueMobileAccessToken;
use App\Http\Controllers\Api\Mobile\V1\Auth\Concerns\RespondsWithAccessToken;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Auth\LoginRequest;
use App\Http\Resources\Mobile\Auth\UserResource;
use App\Models\User;
use App\Support\Security\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in from the mobile app.
 *
 * The same rules as the web login: the same failure throttle
 * ({@see LoginThrottle}), the same identity gate, the same second factor. What
 * differs is only what comes back — a bearer token instead of a session.
 */
class LoginController extends MobileController
{
    use RespondsWithAccessToken;

    /**
     * How long a person has to enter their second-factor code.
     */
    public const CHALLENGE_TTL_MINUTES = 5;

    public function __construct(
        protected LoginRateLimiter $throttle,
        protected IssueMobileAccessToken $issueToken,
    ) {}

    /**
     * Sign in
     *
     * Exchanges an email and password for a bearer token. Send the token on
     * every later call as `Authorization: Bearer <token>`.
     *
     * When the account has two-factor authentication enabled no token is issued
     * yet: the response is `two_factor_required: true` with a short-lived
     * `challenge_token`, which is then sent with the code to
     * `POST /auth/two-factor-challenge`.
     *
     * Only failed attempts count towards the throttle (5 per minute per email,
     * 30 per quarter-hour per address); both answer `429` with `Retry-After`.
     *
     * @unauthenticated
     *
     * @response array{two_factor_required: false, token: string, token_type: 'Bearer', expires_at: string|null, user: UserResource}
     * @response 202 array{two_factor_required: true, challenge_token: string, expires_in: int}
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        if ($this->throttle->tooManyAttempts($request)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $this->throttle->availableIn($request), 'minutes' => ceil($this->throttle->availableIn($request) / 60)]),
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = User::query()
            ->where('email', LoginThrottle::normalize($request->validated('email')))
            ->first();

        if (! $user instanceof User || ! Hash::check($request->validated('password'), $user->password)) {
            $this->throttle->increment($request);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->hasPlatformAccess()) {
            return new JsonResponse([
                'code' => 'account_unavailable',
                'message' => __('This account is not available. Contact support if you believe this is a mistake.'),
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $challenge = Str::random(48);

            Cache::put(
                TwoFactorChallengeController::cacheKey($challenge),
                ['user' => $user->id, 'device_name' => $request->validated('device_name')],
                now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
            );

            return new JsonResponse([
                'two_factor_required' => true,
                'challenge_token' => $challenge,
                'expires_in' => self::CHALLENGE_TTL_MINUTES * 60,
            ], Response::HTTP_ACCEPTED);
        }

        $this->throttle->clear($request);

        return $this->signedIn($this->issueToken, $user, $request->validated('device_name'));
    }
}
