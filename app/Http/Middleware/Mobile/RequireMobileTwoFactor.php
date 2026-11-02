<?php

namespace App\Http\Middleware\Mobile;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of {@see \App\Http\Middleware\RequireTwoFactorForSensitiveRoles}
 * (§36, §32.2): a second factor before the administration panel.
 *
 * The web answers with a redirect to the enrolment screen; a mobile client
 * cannot follow one, so it gets `403 code: two_factor_required` and sends the
 * person to its own security screen. Signing in with two-factor on is already
 * a second factor, so this only asks whether the roles that need it have it set
 * up at all.
 */
class RequireMobileTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! $user->requiresTwoFactorAuthentication()
            || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        return new JsonResponse([
            'code' => 'two_factor_required',
            'message' => __('Two-factor authentication is required for your role. Set it up to continue.'),
        ], Response::HTTP_FORBIDDEN);
    }
}
