<?php

namespace App\Domain\Billing;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Models\Package;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Whether a coupon applies to this purchase, and what it is worth (§9).
 *
 * Every refusal names its own cause. "That code is not valid" gives somebody no
 * way to act; "this code ended on 30 June" and "this code is for the Enterprise
 * package" each tell them something different, and one of them is worth
 * changing their mind over.
 *
 * **Reads only.** Nothing here reserves or redeems anything — checking a code to
 * see what it is worth must not spend it (§9), and this same method runs on
 * every checkout render. Reservation happens once, when a payment is recorded.
 *
 * The order of the checks is deliberate: existence, then the coupon's own
 * window, then what it applies to, then the limits. A code that has expired
 * should say so rather than complaining about a package the applicant would
 * have to change plan to fix.
 */
class CouponValidator
{
    public function __construct(
        protected FeeRuleResolver $fees,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  Money  $registrationFee  the fees the discount could come off,
     * @param  Money  $packageFee  before any discount or tax
     */
    public function validate(
        ?string $code,
        BusinessAccount $account,
        Package $package,
        Money $registrationFee,
        Money $packageFee,
        ?CarbonImmutable $at = null,
    ): CouponOutcome {
        $at ??= CarbonImmutable::now();

        if ($code === null || trim($code) === '') {
            return CouponOutcome::refused('billing.coupons.refused.missing');
        }

        $coupon = Coupon::query()->withCode($code)->first();

        if ($coupon === null) {
            return CouponOutcome::refused('billing.coupons.refused.unknown');
        }

        if (! $coupon->isOpen($at)) {
            return CouponOutcome::refused(
                $coupon->effective_from->gt($at)
                    ? 'billing.coupons.refused.not_started'
                    : 'billing.coupons.refused.expired',
                $coupon,
            );
        }

        // A wholesale code comes off goods, never off what an account paid to join.
        if ($coupon->applies_to === CouponScope::WholesaleOrder) {
            return CouponOutcome::refused('billing.coupons.refused.wholesale_only', $coupon);
        }

        if ($coupon->package_id !== null && $coupon->package_id !== $package->id) {
            return CouponOutcome::refused('billing.coupons.refused.other_package', $coupon);
        }

        if ($coupon->currency_code !== $registrationFee->currency->value) {
            // A coupon priced in another currency cannot be applied by
            // converting it here: a rate nobody agreed is not a discount.
            return CouponOutcome::refused('billing.coupons.refused.currency', $coupon);
        }

        $base = $this->baseFor($coupon, $registrationFee, $packageFee);

        if (! $base->isPositive()) {
            return CouponOutcome::refused('billing.coupons.refused.nothing_to_discount', $coupon);
        }

        return $this->limitRefusal($coupon, $account, $registrationFee->plus($packageFee))
            ?? CouponOutcome::accepted($coupon, $coupon->discountOn($base));
    }

    /**
     * Whether a coupon applies to an ERP wholesale checkout, and what it is worth
     * (§14, P4-6).
     *
     * The same engine and the same order of checks as an activation quote, against
     * the one base a wholesale code comes off: the goods subtotal the server has
     * just priced. A code restricted to a package applies only while the account
     * is on that package — read through {@see Entitlements}, so a lapsed
     * subscription stops qualifying on the day it lapses.
     *
     * Reads only, like {@see validate()}: checking a code on every checkout render
     * never spends it.
     */
    public function validateForWholesale(
        ?string $code,
        BusinessAccount $account,
        Money $subtotal,
        ?CarbonImmutable $at = null,
    ): CouponOutcome {
        $at ??= CarbonImmutable::now();

        if ($code === null || trim($code) === '') {
            return CouponOutcome::refused('billing.coupons.refused.missing');
        }

        $coupon = Coupon::query()->withCode($code)->first();

        if ($coupon === null) {
            return CouponOutcome::refused('billing.coupons.refused.unknown');
        }

        if (! $coupon->isOpen($at)) {
            return CouponOutcome::refused(
                $coupon->effective_from->gt($at)
                    ? 'billing.coupons.refused.not_started'
                    : 'billing.coupons.refused.expired',
                $coupon,
            );
        }

        if ($coupon->applies_to !== CouponScope::WholesaleOrder) {
            return CouponOutcome::refused('billing.coupons.refused.not_for_wholesale', $coupon);
        }

        if ($coupon->package_id !== null
            && $this->entitlements->activePackage($account)?->package_id !== $coupon->package_id) {
            return CouponOutcome::refused('billing.coupons.refused.other_package', $coupon);
        }

        if ($coupon->currency_code !== $subtotal->currency->value) {
            return CouponOutcome::refused('billing.coupons.refused.currency', $coupon);
        }

        if (! $subtotal->isPositive()) {
            return CouponOutcome::refused('billing.coupons.refused.nothing_to_discount', $coupon);
        }

        return $this->limitRefusal($coupon, $account, $subtotal)
            ?? CouponOutcome::accepted($coupon, $coupon->discountOn($subtotal));
    }

    /**
     * The minimum spend and the usage limits, shared by every purchase a coupon
     * can come off. Null when none of them stands in the way.
     */
    protected function limitRefusal(Coupon $coupon, BusinessAccount $account, Money $spend): ?CouponOutcome
    {
        $minimum = $coupon->minimum_spend;

        if ($minimum !== null && $minimum->isPositive() && $spend->lessThan($minimum)) {
            return CouponOutcome::refused('billing.coupons.refused.minimum_spend', $coupon);
        }

        $remaining = $coupon->remainingUses();

        if ($remaining !== null && $remaining < 1) {
            return CouponOutcome::refused('billing.coupons.refused.exhausted', $coupon);
        }

        if ($coupon->per_account_limit !== null
            && $coupon->usesBy($account->id) >= $coupon->per_account_limit) {
            return CouponOutcome::refused('billing.coupons.refused.already_used', $coupon);
        }

        return null;
    }

    /**
     * The amount this coupon's scope lets it come off.
     *
     * §9 keeps the registration fee and the package fee as separate amounts, so
     * a coupon for one of them is worked out against that one alone — otherwise
     * "20% off the package fee" would quietly discount the registration fee too.
     */
    public function baseFor(Coupon $coupon, Money $registrationFee, Money $packageFee): Money
    {
        $currency = Currency::from($coupon->currency_code);
        $base = Money::zero($currency);

        foreach ($coupon->applies_to->allocationTypes() as $type) {
            $base = $base->plus(match ($type->value) {
                'registration_fee' => $registrationFee,
                'package_fee' => $packageFee,
                default => Money::zero($currency),
            });
        }

        return $base;
    }
}
