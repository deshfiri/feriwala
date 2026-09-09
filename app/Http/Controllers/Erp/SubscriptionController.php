<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Actions\CancelSubscription;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\Queries\AccountSubscription;
use App\Domain\Package\SubscriptionPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * An account's own subscription (§8.2, §8.4).
 *
 * Self-scoped by construction: the account comes from the signed-in person's
 * membership, never from the URL, so there is no identifier to substitute for
 * somebody else's (§31.3).
 *
 * Reachable throughout the funnel, not only after activation. "What did I
 * choose and what will it cost" is a question an applicant asks while they are
 * still deciding whether to pay, and a screen that appears only once the money
 * has cleared answers it too late.
 */
class SubscriptionController extends Controller
{
    use ResolvesBusinessAccount;

    public function show(
        Request $request,
        AccountSubscription $subscriptions,
        SubscriptionPolicy $policy,
    ): Response {
        $account = $this->businessAccountFor($request);

        $term = $this->renewableTerm($account);

        return Inertia::render('settings/subscription', [
            'current' => $subscriptions->current($account),
            'history' => $subscriptions->history($account),

            /*
             * The same question the renewal screen asks, answered here so the
             * button and the route agree. The server decides; the page only
             * renders what it is told (§36.1) and the route checks again.
             */
            'can' => ['renew' => $term !== null && $policy->isRenewable($term)],

            // Why not, in the account holder's own words. A missing button that
            // will not say why sends somebody to support to find out.
            'renewal_blocker' => $policy->renewalBlocker($term),
        ]);
    }

    /**
     * Cancel the current term (§8.2).
     *
     * Self-scoped: the term is found through the signed-in person's own account,
     * so there is no identifier to substitute for somebody else's (§31.3).
     */
    public function cancel(Request $request, CancelSubscription $cancel): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $term = $account->packages()
            ->whereIn('status', UserPackageStatus::entitling())
            ->latest('id')
            ->first();

        if ($term === null) {
            return back()->withErrors(['reason' => __('package.cancel.nothing_to_cancel')]);
        }

        try {
            $cancel->handle($term, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('package.cancel.done')]);

        return back();
    }

    /**
     * The term a renewal would carry on from.
     *
     * The pointer where it still holds, the newest live-or-lapsed term
     * otherwise — cancelling and expiring do not clear `current_user_package_id`,
     * and a renewal chained off a closed term would carry on from nothing.
     */
    protected function renewableTerm(BusinessAccount $account): ?UserPackage
    {
        $pointed = $account->currentPackage()->first();

        if ($pointed !== null && ! $pointed->status->isTerminal()) {
            return $pointed;
        }

        return $account->packages()
            ->whereIn('status', [
                UserPackageStatus::Active,
                UserPackageStatus::RenewalDue,
                UserPackageStatus::GracePeriod,
                UserPackageStatus::Expired,
            ])
            ->latest('id')
            ->first();
    }
}
