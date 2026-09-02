<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an unactivated account inside the onboarding funnel (§5.4).
 *
 * Before activation a user may reach only seven areas: profile, KYC, package
 * selection, payment, activation status, support, and activation notifications.
 * Everything else — products, orders, wallet, referrals, withdrawals — is closed
 * until the account is Active.
 *
 * This is an allow-list, not a block-list. A new ERP route is inaccessible to an
 * unactivated account by default, which is the safe direction: forgetting to add
 * a route here locks it down, whereas forgetting to add it to a block-list would
 * quietly expose it.
 */
class EnsureAccountIsActivated
{
    /**
     * Route name prefixes an unactivated account may reach (§5.4).
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTE_PREFIXES = [
        'onboarding.',      // activation status and the stepper
        'profile.',
        'kyc.',
        'packages.',        // selection and comparison
        'payment.',
        'support.',
        'notifications.',
        'settings.',        // password, 2FA, language
        'locale.',
        'logout',
        'verification.',    // email and mobile verification
        'password.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isActivated()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        // Redirected rather than refused with a 403: the user has done nothing
        // wrong, they simply have steps left. The stepper tells them which.
        return redirect()
            ->route('onboarding.status')
            ->with('info', 'Finish setting up your account to reach this.');
    }

    protected function isAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            return false;
        }

        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
