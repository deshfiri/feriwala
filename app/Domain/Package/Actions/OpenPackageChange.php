<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\PackageChangePlanner;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Starts a move to another package (§8.3).
 *
 * Like a renewal, a **new subscription record** carrying its own captured terms
 * and its own source — an account's history should say it moved from one plan to
 * another and when, and rewriting the row it was on would erase exactly that.
 *
 * Nothing changes until the money clears. The new term is created awaiting
 * payment, and the status machine will not let it entitle before then, so an
 * unpaid upgrade cannot hand out a larger limit and an unpaid downgrade cannot
 * take one away.
 *
 * A downgrade the account does not fit is refused here as well as on the screen
 * (D16). The guard reports what stands in the way — what they have, what the
 * package allows, how many must go — and an authorised administrator may
 * override it through {@see OverrideDowngradeLimit}, which needs a permission
 * and a recorded reason.
 */
class OpenPackageChange
{
    public function __construct(
        protected PackageChangePlanner $planner,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, int>  $currentCounts  what the account holds today
     */
    public function handle(
        BusinessAccount $account,
        UserPackage $current,
        Package $target,
        array $currentCounts = [],
    ): UserPackage {
        if (! $target->isAvailable()) {
            throw new RuntimeException('That package is not currently available.');
        }

        return $this->database->transaction(function () use ($account, $current, $target, $currentCounts) {
            /** @var UserPackage $locked */
            $locked = UserPackage::query()->lockForUpdate()->findOrFail($current->id);

            if ($locked->business_account_id !== $account->id) {
                throw new RuntimeException('That subscription belongs to another account.');
            }

            if (! in_array($locked->status, UserPackageStatus::entitling(), true)) {
                // Nothing to move from. A term awaiting payment is changed by
                // choosing again; a closed one is replaced by a new purchase.
                throw new RuntimeException('That subscription cannot be changed.');
            }

            if ($locked->package_id === $target->id) {
                throw new RuntimeException('That account is already on this package.');
            }

            $plan = $this->planner->plan($locked, $target, $currentCounts);

            if (! $plan->isAllowed()) {
                throw new RuntimeException('This account is over the limits of that package.');
            }

            // One change in flight at a time. Choosing a different package
            // before paying replaces the previous choice rather than queueing
            // a second, exactly as first-time selection does.
            $this->supersedePending($account);

            return UserPackage::create([
                'business_account_id' => $account->id,
                'package_id' => $target->id,
                'status' => UserPackageStatus::PendingPayment,
                'source' => $plan->direction,

                'paid_fee_minor' => $plan->payable(),
                'currency_code' => $plan->payable()->currency->value,

                'terms' => $plan->terms->toArray(),
                'terms_captured_at' => now(),

                /*
                 * The dates the plan worked out, stored now so what was quoted
                 * is what activates. Recomputing them at settlement would move
                 * the effective date by however long the payment took.
                 */
                'started_at' => $plan->effectiveFrom,
                'expires_at' => $plan->expiresAt,
                'grace_ends_at' => $plan->expiresAt?->addDays($plan->terms->gracePeriodDays ?? 0),

                'renews_user_package_id' => $locked->id,
            ]);
        });
    }

    /**
     * The change already opened and not yet paid for.
     */
    public function pendingChangeFor(BusinessAccount $account): ?UserPackage
    {
        return $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->whereIn('source', [SubscriptionSource::Upgrade, SubscriptionSource::Downgrade])
            ->latest('id')
            ->first();
    }

    protected function supersedePending(BusinessAccount $account): void
    {
        $pending = $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->whereIn('source', [SubscriptionSource::Upgrade, SubscriptionSource::Downgrade])
            ->lockForUpdate()
            ->get();

        foreach ($pending as $subscription) {
            // Through the machine, not by assignment: PendingPayment is allowed
            // to become Superseded and nothing else here is.
            $subscription->transitionTo(UserPackageStatus::Superseded)->save();
        }
    }
}
