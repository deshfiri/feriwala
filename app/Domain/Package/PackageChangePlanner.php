<?php

namespace App\Domain\Package;

use App\Domain\Package\Data\DowngradeAssessment;
use App\Domain\Package\Data\PackageChangePlan;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Works out what moving to another package costs and when it applies (§8.3).
 *
 * §8.3 asks for prorated charges, additional deposit requirements, minimum
 * balance changes, feature transition rules and an effective date. All of them
 * are answered here, once, from the terms the account **holds** and the terms it
 * would be moving **to** — never from a live package row on one side and a
 * snapshot on the other, which is how two halves of the same sum come to
 * disagree.
 *
 * The two directions are deliberately not symmetrical, because the risk is not
 * symmetrical:
 *
 *   - **An upgrade applies at once.** Somebody paying for more capacity needs it
 *     now, and the billing cycle is left alone: the new term ends when the old
 *     one would have, so a change of package does not quietly become a change of
 *     renewal date.
 *   - **A downgrade applies at the end of the term.** The account has already
 *     paid for what it holds, and taking capacity away mid-term would remove
 *     something already bought. It also gives whoever is over a limit the rest
 *     of the term to get under it (D16).
 */
class PackageChangePlanner
{
    /** Whether the unused part of the current term is credited (§8.3). */
    public const PRORATION = 'package.prorate_upgrades';

    public function __construct(
        protected DowngradeGuard $guard,
        protected SettingsRepository $settings,
    ) {}

    /**
     * @param  array<string, int>  $currentCounts  what the account holds today,
     *                                             keyed by PackageFeature value
     */
    public function plan(
        UserPackage $current,
        Package $target,
        array $currentCounts = [],
        ?CarbonImmutable $at = null,
    ): PackageChangePlan {
        $at ??= CarbonImmutable::instance(now());

        $held = $current->terms();
        $terms = SubscriptionTerms::capture($target->load(['features', 'charges']));

        $currency = Currency::from($terms->currencyCode);

        $direction = $this->directionFor($held, $terms);

        $effectiveFrom = $direction === SubscriptionSource::Upgrade
            ? $at
            : ($current->expires_at ?? $at);

        return new PackageChangePlan(
            direction: $direction,
            effectiveFrom: $effectiveFrom,

            /*
             * An upgrade keeps the cycle: it ends when the current term would
             * have. A downgrade begins where the current term ends and runs a
             * full term of its own, because there is nothing left of the old
             * one to keep.
             */
            expiresAt: $direction === SubscriptionSource::Upgrade
                ? $current->expires_at
                : $this->termEnd($effectiveFrom, $terms->validityDays),

            credit: $direction === SubscriptionSource::Upgrade
                ? $this->unusedValue($current, $at, $currency)
                : Money::zero($currency),

            grossFee: $this->grossFee($direction, $terms, $currency),
            additionalDeposit: $this->additionalDeposit($held, $terms, $currency),

            downgrade: $direction === SubscriptionSource::Upgrade
                ? DowngradeAssessment::allowed()
                : $this->guard->assess($target, $currentCounts),

            terms: $terms,
        );
    }

    /**
     * Which way this move goes.
     *
     * By fee, because that is what an account is agreeing to pay and what §8.3
     * prorates. Comparing entitlements instead would need a ranking that no
     * package declares, and a plan with more staff but fewer products is not
     * bigger or smaller — it is different, and it is priced.
     *
     * Equal fees count as a downgrade, so the conservative path applies: the
     * change waits for the end of the term rather than taking capacity away
     * today.
     */
    protected function directionFor(?SubscriptionTerms $held, SubscriptionTerms $target): SubscriptionSource
    {
        $heldFee = $held === null ? 0 : $held->feeMinor;

        return $target->feeMinor > $heldFee
            ? SubscriptionSource::Upgrade
            : SubscriptionSource::Downgrade;
    }

    /**
     * The unused value of the current term (§8.3 prorated charges).
     *
     * The share of what was actually paid that covers days not yet used,
     * rounded **down** so the credit is never more than the account is owed. A
     * term with no expiry has no unused portion to compute — it has not been
     * used up and never will be — and a term already over has none left.
     *
     * Turned off by setting, because §8.3 makes proration something an
     * administrator configures rather than a rule of the platform.
     */
    protected function unusedValue(UserPackage $current, CarbonImmutable $at, Currency $currency): Money
    {
        if (! $this->proratesUpgrades()) {
            return Money::zero($currency);
        }

        $paid = $current->paid_fee_minor;
        $start = $current->started_at;
        $end = $current->expires_at;

        if ($paid === null || $start === null || $end === null || ! $end->isAfter($at)) {
            return Money::zero($currency);
        }

        $totalDays = $start->diffInDays($end);
        $remainingDays = $at->diffInDays($end);

        if ($totalDays <= 0 || $remainingDays <= 0) {
            return Money::zero($currency);
        }

        // Integer minor units throughout, floored: a credit rounded up would
        // pay an account for a day it still has.
        $credit = intdiv($paid->minorUnits * (int) floor($remainingDays), (int) ceil($totalDays));

        return Money::of(min($credit, $paid->minorUnits), $currency);
    }

    /**
     * What the new package charges for this move.
     *
     * An upgrade is charged at the package fee: the account is buying the
     * larger plan, and the credit for what it already holds comes off it. A
     * downgrade starts a fresh term at the end of this one, which is a renewal
     * in everything but name, so it is charged like one (§8.1).
     */
    protected function grossFee(SubscriptionSource $direction, SubscriptionTerms $terms, Currency $currency): Money
    {
        $minor = $direction === SubscriptionSource::Upgrade
            ? $terms->feeMinor
            : ($terms->renewalFeeMinor ?? $terms->feeMinor);

        return Money::of($minor, $currency);
    }

    /**
     * The extra deposit a larger package asks for (§8.3).
     *
     * Only the difference. An account that has already lodged the smaller
     * package's deposit is not asked for it twice, and a package requiring less
     * does not generate a refund here — releasing a deposit is its own decision
     * with its own approval.
     */
    protected function additionalDeposit(?SubscriptionTerms $held, SubscriptionTerms $target, Currency $currency): Money
    {
        $lodged = $held === null ? 0 : $held->requiredDepositMinor;

        $difference = $target->requiredDepositMinor - $lodged;

        return Money::of(max($difference, 0), $currency);
    }

    protected function termEnd(CarbonImmutable $from, ?int $validityDays): ?CarbonImmutable
    {
        return $validityDays === null ? null : $from->addDays($validityDays);
    }

    protected function proratesUpgrades(): bool
    {
        try {
            $setting = $this->settings->get(self::PRORATION);
        } catch (Throwable) {
            return true;
        }

        return $setting === null ? true : (bool) $setting;
    }
}
