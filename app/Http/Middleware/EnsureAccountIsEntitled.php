<?php

namespace App\Http\Middleware;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\PackageFeatureType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one gate that says whether a package pays for this feature (§8.1, §8.4).
 *
 * Hiding a control is presentation; this is enforcement. A feature an account
 * has not bought has to refuse the URL as well as the button, or the limit is
 * decoration — anyone who has ever seen the route can still reach it.
 *
 * Read from {@see Entitlements}, which answers from the subscription's own
 * captured terms rather than the package row as it stands today (§8.3). An
 * administrator editing a plan must not silently withdraw a facility from
 * everyone already paying for it.
 *
 * **Business features only.** Administration is identity plus permission and
 * never comes through here (D23) — a Feriwala staff member has no package, and
 * a gate that asked them for one would lock them out of their own back office.
 * Nor does the §5.4 funnel: onboarding, verification, package selection,
 * checkout, profile and subscription are how an account *gets* entitled, and
 * gating them on being entitled is a door that locks from the inside.
 */
class EnsureAccountIsEntitled
{
    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $granted = PackageFeature::tryFrom($feature);

        if ($granted === null) {
            // A typo in a route definition must not fail open. It is a
            // programming error, and it says so.
            throw new InvalidArgumentException(
                "[{$feature}] is not a package feature, so it cannot gate a route."
            );
        }

        $user = $request->user();
        $account = $user instanceof User ? $user->businessAccount : null;

        /*
         * No business account is not this gate's decision to make. The identity
         * gate and `business.activated` have already had their say by the time
         * a request arrives here; answering again would only mean answering
         * differently, and refusing here would refuse platform staff.
         */
        if ($account === null) {
            return $next($request);
        }

        if (! $this->isEntitled($account, $granted)) {
            abort(403, __('Your package does not include this.'));
        }

        return $next($request);
    }

    /**
     * Whether the feature is available at all.
     *
     * A limit gates on having any allowance rather than on the count: whether
     * there is room for one *more* depends on what is already there, and the
     * caller counting it is the only thing that knows. This answers the prior
     * question — is the door there at all.
     */
    protected function isEntitled(BusinessAccount $account, PackageFeature $feature): bool
    {
        return match ($feature->type()) {
            PackageFeatureType::Boolean => $this->entitlements->allows($account, $feature),

            // Null is unlimited, zero is a package that grants none of this.
            PackageFeatureType::Limit => $this->entitlements->limit($account, $feature) !== 0,

            // Support level and report access describe *how* a facility is
            // served, not whether it is. Gating a route on one would refuse
            // everybody on the standard tier.
            PackageFeatureType::Text => throw new InvalidArgumentException(
                "[{$feature->value}] describes a service level, not access, so it cannot gate a route."
            ),
        };
    }
}
