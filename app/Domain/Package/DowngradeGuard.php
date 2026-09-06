<?php

namespace App\Domain\Package;

use App\Domain\Package\Data\DowngradeAssessment;
use App\Domain\Package\Data\FeatureExcess;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;

/**
 * Stops an account dropping to a package it no longer fits (D16).
 *
 * D16 is emphatic about what must **not** happen: nothing is auto-removed. An
 * account with 63 published products moving to a 50-product package is told it
 * has 63, that the package allows 50, and that 13 must go — and then chooses
 * which. Silently unpublishing the newest thirteen would destroy a shop's
 * bestsellers on a billing change.
 *
 * Counts are passed in rather than queried here. The caller knows what it is
 * counting — published products on this account, staff on this membership list,
 * websites live right now — and a guard that guessed would eventually count
 * something subtly different from the thing the limit governs. That is the same
 * reasoning as {@see Entitlements::hasCapacityFor()}, and it is why this can be
 * built and tested before the catalogue exists.
 *
 * The guard reports; it does not decide. An authorised administrator may
 * override the block, but only through {@see Actions\OverrideDowngradeLimit},
 * which requires a permission and a recorded reason.
 */
class DowngradeGuard
{
    /**
     * Limits a downgrade has to respect.
     *
     * Only the ones that hold **existing** things an account would have to give
     * up. An order or SMS quota resets with the term, so exceeding it last month
     * is no reason to refuse a package change today.
     *
     * @return array<int, PackageFeature>
     */
    public static function guardedFeatures(): array
    {
        return [
            PackageFeature::ProductPublishLimit,
            PackageFeature::StaffLimit,
            PackageFeature::WebsiteLimit,
        ];
    }

    /**
     * Whether an account may move to `$target`, given what it currently holds.
     *
     * @param  array<string, int>  $currentCounts  keyed by PackageFeature value
     */
    public function assess(Package $target, array $currentCounts): DowngradeAssessment
    {
        $excess = [];

        foreach (self::guardedFeatures() as $feature) {
            $current = $currentCounts[$feature->value] ?? 0;
            $limit = $target->feature($feature);

            // Null is unlimited (§8.1) — nothing to exceed. A non-integer value
            // means the package is misconfigured for this feature, and refusing
            // a downgrade on a number nobody can read would be worse than
            // letting it through for a limit that is not really set.
            if (! is_int($limit)) {
                continue;
            }

            if ($current > $limit) {
                $excess[] = new FeatureExcess($feature, $current, $limit);
            }
        }

        return $excess === []
            ? DowngradeAssessment::allowed()
            : DowngradeAssessment::blocked($excess);
    }
}
