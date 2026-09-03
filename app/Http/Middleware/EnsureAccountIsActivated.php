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
     * Every entry must match a real route — a prefix that matches nothing is not
     * harmless, it is a §5.4 area the user cannot actually get to. A test asserts
     * this, because the failure mode is silent: `payment.` sat here matching
     * nothing while the checkout routes are named `checkout.`, so payment was
     * closed to exactly the accounts that need it.
     *
     * Administration is deliberately absent. Feriwala staff accounts are Active,
     * so they pass the check above and never reach this list; keeping `admin.`
     * out means a suspended staff member loses the admin panel with everything
     * else, rather than keeping it because a prefix said they could.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTE_PREFIXES = [
        'onboarding.',      // activation status and the stepper
        'profile.',
        'kyc.',
        'packages.',        // selection and comparison
        'checkout.',        // the combined activation payment and its return
        'security.',        // 2FA, passkeys, sessions
        'user-password.',
        'well-known.',      // passkey discovery, needed to sign in at all
        'invitations.',     // a staff invitation may arrive before activation
        'locale.',
        'logout',
        'password.',

        ...self::PENDING_ROUTE_PREFIXES,
    ];

    /**
     * §5.4 areas that do not exist yet.
     *
     * Listed so they are open the moment they are built rather than forgotten,
     * and declared separately so the "no dead prefix" test can tell a deliberate
     * placeholder from a typo. Each entry is a standing reminder: while it
     * matches nothing, that §5.4 area is unreachable for everyone.
     *
     * @var array<int, string>
     */
    public const PENDING_ROUTE_PREFIXES = [
        'support.',         // not built — Phase 8
        'notifications.',   // not built — Phase 8
        'verification.',    // email and mobile verification are not wired yet
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
