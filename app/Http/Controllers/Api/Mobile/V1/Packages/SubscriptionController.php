<?php

namespace App\Http\Controllers\Api\Mobile\V1\Packages;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Actions\CancelSubscription;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\Queries\AccountSubscription;
use App\Domain\Package\SubscriptionPolicy;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Packages\CancelSubscriptionRequest;
use App\Http\Resources\Mobile\Packages\SubscriptionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * An account's own subscription (§8.2, §8.4).
 *
 * Self-scoped by construction: the account comes from the signed-in person's
 * membership, never from the URL, so there is no identifier to substitute for
 * somebody else's (§31.3).
 *
 * Reachable throughout the funnel, not only after activation: "what did I
 * choose and what will it cost" is a question an applicant asks while they are
 * still deciding whether to pay.
 */
class SubscriptionController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected AccountSubscription $subscriptions,
        protected SubscriptionPolicy $policy,
    ) {}

    /**
     * Subscription
     *
     * The current term with its captured features, the history of earlier terms, and whether
     * it can be renewed now. `renewal_blocker` says why not, in words, when it cannot.
     *
     * @response array{current: SubscriptionResource|null, history: list<SubscriptionResource>, can: array{renew: bool}, renewal_blocker: string|null}
     */
    public function show(Request $request): JsonResponse
    {
        return $this->summary($this->businessAccountFor($request));
    }

    /**
     * Cancel the subscription
     *
     * Ends the current term now, with a `reason` of at least five characters. Money already paid
     * is not refunded by this. Returns the subscription as `show` does. `422` when there is no
     * current term to cancel or it cannot be cancelled.
     *
     * @response array{current: SubscriptionResource|null, history: list<SubscriptionResource>, can: array{renew: bool}, renewal_blocker: string|null}
     */
    public function cancel(CancelSubscriptionRequest $request, CancelSubscription $cancel): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $term = $account->packages()
            ->whereIn('status', UserPackageStatus::entitling())
            ->latest('id')
            ->first();

        if ($term === null) {
            throw ValidationException::withMessages(['reason' => __('package.cancel.nothing_to_cancel')]);
        }

        try {
            /** @var User $actor */
            $actor = $request->user();

            $cancel->handle($term, $actor, $request->validated('reason'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return $this->summary($account->refresh());
    }

    protected function summary(BusinessAccount $account): JsonResponse
    {
        $term = $this->renewableTerm($account);
        $current = $this->subscriptions->current($account);

        return new JsonResponse([
            'current' => $current === null ? null : new SubscriptionResource($current),
            'history' => SubscriptionResource::collection(collect($this->subscriptions->history($account)))->resolve(),
            'can' => ['renew' => $term !== null && $this->policy->isRenewable($term)],
            'renewal_blocker' => $this->policy->renewalBlocker($term),
        ]);
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
