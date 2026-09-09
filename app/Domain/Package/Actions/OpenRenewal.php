<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\SubscriptionPolicy;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Starts a renewal of the term an account is on (§8.2).
 *
 * A renewal is a **new subscription record**, not the old one given more time.
 * Every term an account has held is history: what it cost, what it granted, and
 * when it ran — and extending a row in place would overwrite the answer to all
 * three. The new row carries {@see SubscriptionSource::Renewal}, so a report can
 * tell a carried-on term from a first purchase.
 *
 * Terms are captured **fresh**, at the moment the renewal is opened. That is the
 * point §8.3 defines for a change of terms: the account is agreeing to what the
 * package says today, sees the price before paying, and is not bound to last
 * year's entitlements by accident. The term running out keeps its own snapshot,
 * untouched.
 *
 * Nothing here grants anything. The renewal is created awaiting payment and the
 * status machine will not let it entitle until it is paid for
 * ({@see ActivateRenewal}) — an unpaid renewal must never extend access.
 *
 * Idempotent by construction: opening a renewal twice returns the same pending
 * row rather than stacking two, so a double-submitted form or a retried request
 * cannot leave an account with a queue of unpaid renewals.
 */
class OpenRenewal
{
    public function __construct(
        protected SubscriptionPolicy $policy,
        protected DatabaseManager $database,
    ) {}

    public function handle(BusinessAccount $account, UserPackage $current): UserPackage
    {
        return $this->database->transaction(function () use ($account, $current) {
            /** @var UserPackage $locked */
            $locked = UserPackage::query()->lockForUpdate()->findOrFail($current->id);

            if ($locked->business_account_id !== $account->id) {
                throw new RuntimeException('That subscription belongs to another account.');
            }

            if (! $this->policy->isRenewable($locked)) {
                throw new RuntimeException('That subscription cannot be renewed yet.');
            }

            $existing = $this->pendingRenewalFor($account);

            if ($existing !== null) {
                return $existing;
            }

            $package = $locked->package;

            if ($package === null) {
                // The catalogue row is gone. Renewing onto terms nobody can read
                // would sell something undefined.
                throw new RuntimeException('The package for this subscription is no longer available.');
            }

            $terms = SubscriptionTerms::capture($package->load(['features', 'charges']));

            return UserPackage::create([
                'business_account_id' => $account->id,
                'package_id' => $package->id,
                'status' => UserPackageStatus::PendingPayment,
                'source' => SubscriptionSource::Renewal,

                // What renewing costs, at the price quoted. The renewal fee
                // where the package sets one; the package fee where it does not
                // (§8.1) — silence there means no separate renewal price, not a
                // free year.
                'paid_fee_minor' => $package->renewal_fee_minor ?? $package->fee_minor,
                'currency_code' => $package->fee_minor->currency->value,

                'terms' => $terms->toArray(),
                'terms_captured_at' => now(),

                // The term this one carries on from, so the chain is readable
                // without inferring it from dates.
                'renews_user_package_id' => $locked->id,
            ]);
        });
    }

    /**
     * A renewal already opened and not yet paid for.
     *
     * Scoped to renewals: an account part-way through choosing a *different*
     * package has a pending row too, and returning that one here would quietly
     * turn a package change into a renewal.
     */
    public function pendingRenewalFor(BusinessAccount $account): ?UserPackage
    {
        return $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->where('source', SubscriptionSource::Renewal)
            ->latest('id')
            ->first();
    }
}
