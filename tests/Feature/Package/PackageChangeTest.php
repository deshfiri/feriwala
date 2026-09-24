<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculatePackageChangeQuote;
use App\Domain\Package\Actions\ActivatePackageChange;
use App\Domain\Package\Actions\OpenPackageChange;
use App\Domain\Package\Data\DowngradeAssessment;
use App\Domain\Package\Data\PackageChangePlan;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\PackageChangePlanner;
use App\Domain\Package\Queries\AccountHoldings;
use App\Notifications\Package\PackageChanged;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Pinned to a whole second, not merely frozen. Timestamps round-trip through
 * the database at second precision, so a clock carrying microseconds makes a
 * term "185 days minus a fraction" — floored to 184 — and the proration
 * arithmetic would depend on when the test happened to run.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 09:00:00'));
});

/*
 * Upgrade and downgrade (P1-39, §8.3).
 *
 * The two directions are not symmetrical, because the risk is not: an upgrade
 * applies at once, a downgrade at the end of the term the account has already
 * paid for.
 */

/**
 * A package with a fee and an optional staff limit.
 */
function changeTestPackage(string $fee, ?int $staffLimit = null, string $deposit = '0.00'): Package
{
    $package = Package::create([
        'slug' => 'plan-'.Str::lower(Str::random(8)),
        'name' => 'Plan '.$fee,
        'fee' => Money::fromDecimal($fee, Currency::BDT),
        'renewal_fee' => Money::fromDecimal($fee, Currency::BDT),
        'renewal_frequency' => 'yearly',
        'validity_days' => 365,
        'grace_period_days' => 14,
        'required_deposit' => Money::fromDecimal($deposit, Currency::BDT),
        'minimum_balance' => Money::zero(Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    if ($staffLimit !== null) {
        $package->features()->create([
            'feature' => PackageFeature::StaffLimit->value,
            'value' => (string) $staffLimit,
        ]);
    }

    return $package->refresh()->load(['features', 'charges']);
}

/**
 * An active account, half way through a term on `$package`.
 *
 * @return array{0: BusinessAccount, 1: UserPackage}
 */
function changeTestAccount(Package $package, int $daysUsed = 180): array
{
    $account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'source' => SubscriptionSource::Purchase,
        'started_at' => now()->subDays($daysUsed),
        'expires_at' => now()->addDays(365 - $daysUsed),
        'grace_ends_at' => now()->addDays(365 - $daysUsed + 14),
        'paid_fee' => $package->fee,
        'currency_code' => 'BDT',
        'terms' => SubscriptionTerms::capture($package)->toArray(),
        'terms_captured_at' => now(),
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return [$account->refresh(), $subscription];
}

describe('which way the change goes', function () {
    it('reads a bigger fee as an upgrade and applies it now', function () {
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [, $current] = changeTestAccount($small);

        $plan = app(PackageChangePlanner::class)->plan($current, $large);

        expect($plan->direction)->toBe(SubscriptionSource::Upgrade)
            ->and($plan->effectiveFrom->toDateString())->toBe(now()->toDateString())
            // The billing cycle is left alone: a change of package must not
            // quietly become a change of renewal date.
            ->and($plan->expiresAt?->toDateString())->toBe($current->expires_at?->toDateString());
    });

    it('reads a smaller fee as a downgrade and waits for the term to end', function () {
        // The account has already paid for what it holds; taking capacity away
        // mid-term would remove something already bought.
        $large = changeTestPackage('9000.00');
        $small = changeTestPackage('5000.00');
        [, $current] = changeTestAccount($large);

        $plan = app(PackageChangePlanner::class)->plan($current, $small);

        expect($plan->direction)->toBe(SubscriptionSource::Downgrade)
            ->and($plan->effectiveFrom->toDateString())
            ->toBe($current->expires_at?->toDateString())
            ->and($plan->expiresAt?->toDateString())
            ->toBe($current->expires_at?->addDays(365)->toDateString());
    });
});

describe('what an upgrade costs', function () {
    it('credits the unused part of the current term', function () {
        // §8.3's prorated charges: half a 5,000 term used, so half is credited
        // against the 9,000 plan.
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [, $current] = changeTestAccount($small, daysUsed: 180);

        $plan = app(PackageChangePlanner::class)->plan($current, $large);

        expect($plan->grossFee->toDecimal())->toBe('9000.00')
            // 185 of 365 days left, floored: 5000.00 * 185 / 365.
            ->and($plan->credit->toDecimal())->toBe('2534.24')
            ->and($plan->payable()->toDecimal())->toBe('6465.76');
    });

    it('never turns a large credit into a payout', function () {
        /*
         * Refunds are their own workflow with their own approval (§27). A
         * package change must not route money out of the platform by
         * arithmetic on a screen that never asked anyone.
         *
         * Asserted on the plan directly. An upgrade cannot reach this today —
         * the credit is capped at what was paid and the fee is by definition
         * larger — but "cannot happen" is exactly the kind of guard that stops
         * being true when somebody changes how the credit is worked out.
         */
        $package = changeTestPackage('5000.00');
        [, $current] = changeTestAccount($package);

        $plan = new PackageChangePlan(
            direction: SubscriptionSource::Upgrade,
            effectiveFrom: CarbonImmutable::instance(now()),
            expiresAt: null,
            credit: Money::fromDecimal('9000.00', Currency::BDT),
            grossFee: Money::fromDecimal('5000.00', Currency::BDT),
            additionalDeposit: Money::zero(Currency::BDT),
            downgrade: DowngradeAssessment::allowed(),
            terms: $current->terms(),
        );

        expect($plan->payable()->isZero())->toBeTrue()
            ->and($plan->payable()->isNegative())->toBeFalse();
    });

    it('credits nothing on a term that has no unused part', function () {
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [, $current] = changeTestAccount($small, daysUsed: 365);

        $plan = app(PackageChangePlanner::class)->plan($current, $large);

        expect($plan->credit->isZero())->toBeTrue();
    });

    it('asks only for the difference in deposit', function () {
        // §8.3's additional deposit requirement. An account that has already
        // lodged the smaller package's deposit is not asked for it twice.
        $small = changeTestPackage('5000.00', deposit: '2000.00');
        $large = changeTestPackage('9000.00', deposit: '5000.00');
        [, $current] = changeTestAccount($small);

        $plan = app(PackageChangePlanner::class)->plan($current, $large);

        expect($plan->additionalDeposit->toDecimal())->toBe('3000.00');
    });

    it('never refunds a deposit through a downgrade', function () {
        // Releasing a deposit is its own decision with its own approval.
        $large = changeTestPackage('9000.00', deposit: '5000.00');
        $small = changeTestPackage('5000.00', deposit: '2000.00');
        [, $current] = changeTestAccount($large);

        $plan = app(PackageChangePlanner::class)->plan($current, $small);

        expect($plan->additionalDeposit->isZero())->toBeTrue();
    });
});

describe('the itemised quote', function () {
    it('keeps the credit as its own line, before tax', function () {
        /*
         * §9 requires the components stored separately, and taxing the full fee
         * before deducting the credit would charge tax on days the account is
         * not buying.
         */
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [$account, $current] = changeTestAccount($small);

        $plan = app(PackageChangePlanner::class)->plan($current, $large);
        $quote = app(CalculatePackageChangeQuote::class)->handle($plan, $account);

        $types = array_map(fn ($line) => $line->type->value, $quote->lines);

        expect($types)->toContain('package_fee')
            ->and($types)->toContain('discount')
            ->and($quote->total()->toDecimal())->toBe($plan->payable()->toDecimal());
    });
});

describe('opening a change', function () {
    it('creates a new subscription that grants nothing yet', function () {
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [$account, $current] = changeTestAccount($small);

        $change = app(OpenPackageChange::class)->handle($account, $current, $large);

        expect($change->id)->not->toBe($current->id)
            ->and($change->status)->toBe(UserPackageStatus::PendingPayment)
            ->and($change->source)->toBe(SubscriptionSource::Upgrade)
            ->and($change->renews_user_package_id)->toBe($current->id)
            ->and($change->entitlesNow())->toBeFalse()
            // Untouched until the money clears.
            ->and($current->refresh()->status)->toBe(UserPackageStatus::Active);
    });

    it('stores the dates that were quoted', function () {
        // Recomputing them at settlement would move the effective date by
        // however long the payment took.
        $large = changeTestPackage('9000.00');
        $small = changeTestPackage('5000.00');
        [$account, $current] = changeTestAccount($large);

        $change = app(OpenPackageChange::class)->handle($account, $current, $small);

        expect($change->started_at?->toDateString())
            ->toBe($current->expires_at?->toDateString());
    });

    it('refuses a downgrade the account does not fit', function () {
        /*
         * D16: nothing is auto-removed. The guard blocks and reports what is in
         * the way rather than silently unpublishing somebody's bestsellers on a
         * billing change.
         */
        $large = changeTestPackage('9000.00', staffLimit: 10);
        $small = changeTestPackage('5000.00', staffLimit: 1);
        [$account, $current] = changeTestAccount($large);

        $plan = app(PackageChangePlanner::class)->plan($current, $small, [
            PackageFeature::StaffLimit->value => 5,
        ]);

        expect($plan->isAllowed())->toBeFalse()
            ->and($plan->downgrade->totalToRemove())->toBe(4);

        expect(fn () => app(OpenPackageChange::class)->handle(
            $account,
            $current,
            $small,
            [PackageFeature::StaffLimit->value => 5],
        ))->toThrow(RuntimeException::class);
    });

    it('replaces an earlier unpaid change rather than queueing a second', function () {
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        $larger = changeTestPackage('12000.00');
        [$account, $current] = changeTestAccount($small);

        $first = app(OpenPackageChange::class)->handle($account, $current, $large);
        $second = app(OpenPackageChange::class)->handle($account, $current, $larger);

        expect($first->refresh()->status)->toBe(UserPackageStatus::Superseded)
            ->and($second->status)->toBe(UserPackageStatus::PendingPayment);
    });

    it('refuses a package the account is already on', function () {
        $package = changeTestPackage('5000.00');
        [$account, $current] = changeTestAccount($package);

        expect(fn () => app(OpenPackageChange::class)->handle($account, $current, $package))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a subscription belonging to somebody else', function () {
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [$account] = changeTestAccount($small);
        [, $theirs] = changeTestAccount($small);

        expect(fn () => app(OpenPackageChange::class)->handle($account, $theirs, $large))
            ->toThrow(RuntimeException::class);
    });
});

describe('when the change is paid for', function () {
    it('ends the term an upgrade replaces', function () {
        // Cancelled rather than expired: it did not run out, it was ended early
        // by a change the account asked for.
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [$account, $current] = changeTestAccount($small);

        $change = app(OpenPackageChange::class)->handle($account, $current, $large);
        app(ActivatePackageChange::class)->handle($change);

        expect($change->refresh()->status)->toBe(UserPackageStatus::Active)
            ->and($current->refresh()->status)->toBe(UserPackageStatus::Cancelled)
            ->and($current->cancelled_at)->not->toBeNull()
            ->and($account->refresh()->current_user_package_id)->toBe($change->id);
    });

    it('leaves the current term running when a downgrade is scheduled', function () {
        $large = changeTestPackage('9000.00', staffLimit: 10);
        $small = changeTestPackage('5000.00', staffLimit: 2);
        [$account, $current] = changeTestAccount($large);

        $change = app(OpenPackageChange::class)->handle($account, $current, $small);
        app(ActivatePackageChange::class)->handle($change);

        expect($current->refresh()->status)->toBe(UserPackageStatus::Active);
    });

    it('keeps the larger entitlements until the downgrade actually starts', function () {
        /*
         * "Effective at the end of the term" has to mean something. The pointer
         * moves to the smaller term straight away, and `Entitlements` falls
         * back to whichever term entitles *now* — so the account keeps what it
         * paid for until the day it ends.
         */
        $large = changeTestPackage('9000.00', staffLimit: 10);
        $small = changeTestPackage('5000.00', staffLimit: 2);
        [$account, $current] = changeTestAccount($large);

        $change = app(OpenPackageChange::class)->handle($account, $current, $small);
        app(ActivatePackageChange::class)->handle($change);

        $entitlements = app(Entitlements::class);

        expect($entitlements->activePackage($account->refresh())?->id)->toBe($current->id)
            ->and($entitlements->limit($account->refresh(), PackageFeature::StaffLimit))->toBe(10);
    });

    it('runs once however many times the gateway says so', function () {
        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [$account, $current] = changeTestAccount($small);

        $change = app(OpenPackageChange::class)->handle($account, $current, $large);

        app(ActivatePackageChange::class)->handle($change);
        $startedAt = $change->refresh()->started_at;

        app(ActivatePackageChange::class)->handle($change);

        expect($change->refresh()->started_at?->toIso8601String())
            ->toBe($startedAt?->toIso8601String());
    });

    it('tells the account holder when it applies', function () {
        Notification::fake();

        $small = changeTestPackage('5000.00');
        $large = changeTestPackage('9000.00');
        [$account, $current] = changeTestAccount($small);

        $change = app(OpenPackageChange::class)->handle($account, $current, $large);
        app(ActivatePackageChange::class)->handle($change);

        Notification::assertSentTo($account->owner, PackageChanged::class);
    });
});

describe('the change screen', function () {
    it('lists what each plan would cost and when it applies', function () {
        $small = changeTestPackage('5000.00');
        changeTestPackage('9000.00');
        [$account] = changeTestAccount($small);

        $this->actingAs($account->owner)
            ->get(route('subscription.change.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/package-change')
                ->has('options', 1)
                ->where('options.0.direction', 'upgrade')
                ->has('options.0.effective_from')
                ->has('options.0.payable'),
            );
    });

    it('shows a plan the account is over rather than hiding it', function () {
        // D16 again: the three figures, on the screen, not a refusal.
        $large = changeTestPackage('9000.00', staffLimit: 10);
        changeTestPackage('5000.00', staffLimit: 0);
        [$account] = changeTestAccount($large);

        $this->actingAs($account->owner)
            ->get(route('subscription.change.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('options', 1)
                ->where('options.0.is_allowed', false)
                ->where('options.0.downgrade.total_to_remove', 1),
            );
    });

    it('counts what the account actually holds', function () {
        $package = changeTestPackage('5000.00');
        [$account] = changeTestAccount($package);

        expect(app(AccountHoldings::class)->counts($account))
            ->toHaveKey(PackageFeature::StaffLimit->value, 1);
    });
});
