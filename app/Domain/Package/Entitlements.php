<?php

namespace App\Domain\Package;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\PackageFeatureType;
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

        $package = $userPackage->package;

        // A subscription whose package row has gone is not an entitlement —
        // fall back to the feature's default rather than assuming anything.
        if ($package === null) {
            return $feature->default();
        }

        return $package->feature($feature);
    }

    /**
     * The account's package, if it currently entitles them to anything.
     */
    public function activePackage(BusinessAccount $account): ?UserPackage
    {
        $userPackage = $account->relationLoaded('currentPackage')
            ? $account->currentPackage
            : $account->currentPackage()->with('package.features')->first();

        if ($userPackage === null || ! $userPackage->entitlesNow()) {
            return null;
        }

        return $userPackage;
    }
}
