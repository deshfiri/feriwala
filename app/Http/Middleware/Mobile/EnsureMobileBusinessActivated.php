<?php

namespace App\Http\Middleware\Mobile;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of {@see \App\Http\Middleware\EnsureBusinessAccountIsActivated}
 * (§5.4, D23).
 *
 * The web gate is an allow-list keyed on route names and answers with a
 * redirect. A mobile client has neither: it cannot follow a redirect to an
 * onboarding page, and these routes are not named like the web ones. So the
 * allow-list is inverted into structure — the §5.4 areas that an unactivated
 * account may reach are registered *outside* the `mobile.activated` group in
 * `routes/mobile/`, and everything inside it is closed to them by default.
 *
 * The answer is a 403 with a machine-readable `code`, so the app can send the
 * person to its own onboarding screen.
 */
class EnsureMobileBusinessActivated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasActivatedBusinessAccount()) {
            return $next($request);
        }

        // Platform staff and invitees have no account. They are not part-way
        // through onboarding; the business ERP is simply not theirs (D23).
        if ($user->businessAccount === null) {
            return new JsonResponse([
                'code' => 'no_business_account',
                'message' => __('This area is for business accounts.'),
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'code' => 'account_not_activated',
            'message' => __('Finish setting up your account to reach this.'),
        ], Response::HTTP_FORBIDDEN);
    }
}
