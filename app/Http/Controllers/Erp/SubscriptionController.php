<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Package\Queries\AccountSubscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function show(Request $request, AccountSubscription $subscriptions): Response
    {
        $account = $this->businessAccountFor($request);

        return Inertia::render('settings/subscription', [
            'current' => $subscriptions->current($account),
            'history' => $subscriptions->history($account),
        ]);
    }
}
