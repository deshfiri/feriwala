<?php

namespace App\Domain\Package;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\PackageFeatureType;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;

/**
 * Answers "may this account do X, and how much of it" (§8.1, §5.4).
 *
 * The single place package entitlements are read. Feature checks appear all over
 * the ERP — publishing a product, adding staff, opening a website, calling the
 * API — and each one asking the question its own way is how an account ends up
 * able to publish 51 products on a 50-product package.
 *
 * An account with no active package is entitled to nothing. It is still in the
 * activation funnel (§5.4) or its package has lapsed (§8.4); either way, the
 * safe answer is no.
 */
class Entitlements
{
    /**
     * Whether a boolean facility is granted.
     */
    public function allows(BusinessAccount $account, PackageFeature $feature): bool
    {
        $value = $this->value($account, $feature);

        return $value === true;
    }

    /**
     * The cap for a limited feature. Null means unlimited.
     */
    public function limit(BusinessAccount $account, PackageFeature $feature): ?int
    {
        $value = $this->value($account, $feature);

        return is_int($value) ? $value : null;
    }

    /**
     * Whether one more of something is allowed.
     *
     * Takes the current count rather than counting internally, because the
     * caller knows what it is counting — published products on this website,
     * staff on this account — and a check that guesses would be wrong somewhere.
     */
    public function hasCapacityFor(
        BusinessAccount $account,
        PackageFeature $feature,
        int $currentCount,
        int $adding = 1,
    ): bool {
        $limit = $this->limit($account, $feature);

        // Unlimited only when the package explicitly says so, which is why an
        // account with no package returns 0 rather than null.
        if ($limit === null && $this->activePackage($account) !== null) {
            return true;
        }

        return $currentCount + $adding <= ($limit ?? 0);
    }

    /**
     * How many more are allowed. Null means unlimited.
     */
    public function remaining(BusinessAccount $account, PackageFeature $feature, int $currentCount): ?int
    {
        $limit = $this->limit($account, $feature);

        if ($limit === null && $this->activePackage($account) !== null) {
            return null;
        }

        return max(0, ($limit ?? 0) - $currentCount);
    }

    /**
     * The raw entitlement value.
     */
    public function value(BusinessAccount $account, PackageFeature $feature): bool|int|string|null
    {
        $userPackage = $this->activePackage($account);

        if ($userPackage === null) {
            // No package means no entitlements — but a limit still reads as 0
            // rather than null, so "unlimited" is never inferred from absence.
            return match ($feature->type()) {
                PackageFeatureType::Limit => 0,
                PackageFeatureType::Boolean => false,
                default => null,
            };
        }

        /*
         * The subscription's own terms, not the package's current ones (§8.3).
         *
         * Reading the live row made an administrator's edit change what every
         * existing subscriber was entitled to, retroactively and without anyone
         * agreeing to it. A change of terms is an upgrade or a renewal, which
         * an account accepts; it is not something that happens to them between
         * one request and the next.
         *
         * Falls back to the package only for subscriptions written before
         * snapshots existed — and to the feature's default when even that has
         * gone, rather than assuming anything.
         */
        $terms = $userPackage->terms();

        return $terms === null ? $feature->default() : $terms->feature($feature);
    }

    /**
     * The account's package, if it currently entitles them to anything.
     *
     * `current_user_package_id` is a pointer written at activation, and nothing
     * rewrites it when a term ends. So it is checked first — it is the cheap,
     * usually-right answer — and then verified against
     * {@see UserPackage::entitlesNow()} rather than trusted.
     *
     * When the pointer leads nowhere entitling, the newest subscription that
     * does is the effective one. A renewal or an upgrade writes a new row, and
     * an account that has paid must not be told it has nothing because a column
     * still names the term it replaced.
     */
    public function activePackage(BusinessAccount $account): ?UserPackage
    {
        $pointed = $account->relationLoaded('currentPackage')
            ? $account->currentPackage
            : $account->currentPackage()->with('package.features')->first();

        if ($pointed !== null && $pointed->entitlesNow()) {
            return $pointed;
        }

        return $account->packages()
            ->whereIn('status', UserPackageStatus::entitling())
            ->with('package.features')
            ->latest('id')
            ->get()
            ->first(fn (UserPackage $subscription) => $subscription->entitlesNow());
    }
}
