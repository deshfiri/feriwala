<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outermost gate: may this person use the platform at all? (§6, D23)
 *
 * Asked of the **login identity**, so it outranks everything — roles,
 * permissions, and whatever state the person's business is in. A suspended
 * login loses the admin panel exactly as it loses the wallet. That is the whole
 * reason identity status exists separately from account status, and why there is
 * no `admin.*` exemption anywhere in this file.
 *
 * Refused identities are signed out rather than merely redirected. Leaving a
 * suspended session alive means every request depends on this middleware being
 * on the route; ending it means the suspension holds even if some future route
 * forgets to include the gate.
 */
class EnsureIdentityHasPlatformAccess
{
    /**
     * Routes a refused identity may still reach.
     *
     * Only what it takes to be told what happened and to leave. Notably not
     * `password.` — someone suspended does not get to reset their way back in.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTE_PREFIXES = [
        'logout',
        'locale.',
        'suspended',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasPlatformAccess()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors(['email' => __('This account is not available. Contact support if you believe this is a mistake.')]);
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
