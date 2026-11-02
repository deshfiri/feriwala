<?php

namespace App\Http\Middleware\Mobile;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of Laravel's `RequirePassword` — "confirm your password
 * before this" (§36, §32.2) — for sensitive administrative actions.
 *
 * The web remembers a confirmation in the session for a few hours. A token
 * client has no session, and remembering it server-side per token would be
 * state the API deliberately does not keep (§40), so the password is simply
 * sent as `current_password` with each sensitive request. That is stricter than
 * the web, not looser.
 *
 * Wrong guesses are limited per person, so a stolen unlocked phone cannot be
 * used to guess the password through an endpoint that is not the login.
 *
 * - no `current_password`        → `423 code: password_required`
 * - wrong `current_password`     → `422` on `current_password`
 * - too many wrong ones          → `429`
 */
class RequireMobilePassword
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $supplied = $request->input('current_password');

        if (! is_string($supplied) || $supplied === '') {
            return new JsonResponse([
                'code' => 'password_required',
                'message' => __('Confirm your password to continue.'),
            ], Response::HTTP_LOCKED);
        }

        $key = 'mobile-confirm-password:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'current_password' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                    'minutes' => ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (! Hash::check($supplied, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'current_password' => __('The provided password is incorrect.'),
            ]);
        }

        RateLimiter::clear($key);

        return $next($request);
    }
}
