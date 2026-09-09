<?php

namespace App\Http\Middleware;

use App\Domain\Access\Enums\PlatformRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A second factor before the administration panel (§36, §32.2).
 *
 * A password is one secret, and the roles this guards can move money, read
 * personal documents, restore backups and change what everyone else is allowed
 * to do. §32.2 says those actions need more than a permission behind them; this
 * is where that becomes a condition of opening the panel at all, rather than a
 * prompt somebody dismisses at the moment they are about to act.
 *
 * Which roles is {@see PlatformRole::requiresTwoFactor()}, derived from the
 * sensitive-action list rather than kept as a second list here.
 *
 * The answer is a **redirect to the enrolment screen**, not a refusal. Somebody
 * who has just been given a role has done nothing wrong and has one step to
 * take; a 403 would tell them they do not have access, which is the wrong thing
 * to believe about their own account.
 */
class RequireTwoFactorForSensitiveRoles
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        return redirect()
            ->route('security.edit')
            ->with('info', __('Two-factor authentication is required for your role. Set it up to continue.'));
    }
}
