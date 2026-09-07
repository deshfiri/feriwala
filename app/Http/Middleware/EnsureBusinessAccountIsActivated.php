<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Navigation\HomeRoute;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an unactivated business inside the onboarding funnel (§5.4, D23).
 *
 * Applied to the **business ERP only**. Administration is governed by identity
 * status plus a platform permission and never reaches this class, which is what
 * lets a Feriwala staff member work the KYC queue without owning a business
 * account, completing commercial KYC, or paying an activation fee.
 *
 * That is not a bypass. A suspended staff member still loses the admin panel —
 * {@see EnsureIdentityHasPlatformAccess} takes it, on the identity, before any
 * of this runs. What changed is *which* question closes the panel: being
 * barred from the platform, rather than not having bought a package.
 *
 * Applies to owner and invited staff alike. An invited member has no onboarding
 * of their own, so the account's status is the only thing that can answer for
 * them — and when the account is suspended, its members lose the ERP with it.
 *
 * An allow-list, not a block-list. A new ERP route is closed to an unactivated
 * account by default: forgetting to list a route locks it down, where
 * forgetting to add it to a block-list would quietly expose it.
 */
class EnsureBusinessAccountIsActivated
{
    /**
     * Route name prefixes reachable before the business is activated (§5.4).
     *
     * Every entry must match a real route — a prefix that matches nothing is
     * not harmless, it is a §5.4 area the user silently cannot reach. A test
     * asserts this, because the failure mode is invisible: `payment.` once sat
     * here matching nothing while the checkout routes were named `checkout.`.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTE_PREFIXES = [
        'onboarding.',      // activation status and the stepper
        'profile.',
        'kyc.',
        'packages.',        // selection and comparison
        'subscription.',    // what they chose, and what it will cost
        'checkout.',        // the combined activation payment and its return
        'security.',        // 2FA, passkeys, sessions
        'user-password.',
        'well-known.',      // passkey discovery, needed to sign in at all
        'staff.invitation.', // joining someone else's account needs no account
        'locale.',
        'logout',
        'password.',

        ...self::PENDING_ROUTE_PREFIXES,
    ];

    /**
     * §5.4 areas that do not exist yet.
     *
     * Listed so they open the moment they are built rather than being
     * forgotten, and declared separately so the "no dead prefix" test can tell
     * a deliberate placeholder from a typo. Each entry is a standing reminder:
     * while it matches nothing, that §5.4 area is unreachable for everyone.
     *
     * @var array<int, string>
     */
    public const PENDING_ROUTE_PREFIXES = [
        'support.',         // not built — Phase 8
        'notifications.',   // not built — Phase 8
        'verification.',    // email and mobile verification are not wired yet
    ];

    public function __construct(
        protected HomeRoute $home,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->hasActivatedBusinessAccount()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        /*
         * Somebody with no account at all is not half-way through onboarding.
         * They are an invitee waiting to join a business, or Feriwala's own
         * staff — and the activation stepper 403s without an account, so
         * sending them there turned "you cannot see this page" into "you cannot
         * use the product" (D23).
         */
        if ($user->businessAccount === null) {
            return redirect()->to($this->home->urlFor($user));
        }

        // Redirected rather than refused with a 403: the account holder has
        // done nothing wrong, they have steps left. The stepper says which.
        return redirect()
            ->route('onboarding.status')
            ->with('info', __('Finish setting up your account to reach this.'));
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
