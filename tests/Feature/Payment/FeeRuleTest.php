<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\ManageFeeRules;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Package\Models\Package;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Fee rules with effective dates (P1-43, §9).
 *
 * A price is never edited. Changing what the registration fee costs opens a new
 * rule and closes the one it replaces, so every past quote still reproduces.
 */

function feeTestPackage(?int $registrationFeeMinor = null): Package
{
    return Package::create([
        'slug' => 'fee-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'fee_minor' => 500000,
        'registration_fee_minor' => $registrationFeeMinor,
        'validity_days' => 365,
        'required_deposit_minor' => 0,
        'minimum_balance_minor' => 0,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ])->refresh()->load(['features', 'charges']);
}

function feeTestRule(
    int $amountMinor,
    string $from = '-1 day',
    ?string $until = null,
    ?int $packageId = null,
): FeeRule {
    return FeeRule::create([
        'fee_type' => FeeType::Registration,
        'package_id' => $packageId,
        'amount_minor' => $amountMinor,
        'currency_code' => 'BDT',
        'effective_from' => now()->modify($from),
        'effective_until' => $until === null ? null : now()->modify($until),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
});

describe('resolving a fee', function () {
    it('uses the global rule in force', function () {
        feeTestRule(100000);

        $fee = app(FeeRuleResolver::class)
            ->registrationFee(feeTestPackage(), Currency::BDT);

        expect($fee->minorUnits)->toBe(100000);
    });

    it('lets a package rule beat the global one', function () {
        // Specificity, not recency: a newer global price must not quietly
        // override a deal struck for one plan.
        $package = feeTestPackage();

        feeTestRule(100000);
        feeTestRule(25000, packageId: $package->id);

        $fee = app(FeeRuleResolver::class)->registrationFee($package, Currency::BDT);

        expect($fee->minorUnits)->toBe(25000);
    });

    it('keeps the package column as the per-package override', function () {
        // It is edited where the rest of the package is, and moving it would
        // mean two screens that can disagree.
        feeTestRule(100000);

        $fee = app(FeeRuleResolver::class)
            ->registrationFee(feeTestPackage(registrationFeeMinor: 40000), Currency::BDT);

        expect($fee->minorUnits)->toBe(40000);
    });

    it('resolves against the moment of the charge, not today', function () {
        /*
         * A reissued quote or a recalculated invoice has to reproduce the
         * arithmetic it did originally.
         */
        feeTestRule(100000, from: '-90 days', until: '-30 days');
        feeTestRule(150000, from: '-30 days');

        $resolver = app(FeeRuleResolver::class);
        $package = feeTestPackage();

        expect($resolver->registrationFee($package, Currency::BDT)->minorUnits)->toBe(150000)
            ->and($resolver->registrationFee(
                $package,
                Currency::BDT,
                CarbonImmutable::instance(now())->subDays(60),
            )->minorUnits)->toBe(100000);
    });

    it('ignores a rule that is closed or not yet open', function () {
        $package = feeTestPackage();

        feeTestRule(100000)->forceFill(['is_active' => false])->save();
        feeTestRule(200000, from: '+10 days');

        expect(app(FeeRuleResolver::class)->registrationFee($package, Currency::BDT)->minorUnits)
            ->toBe(0);
    });

    it('charges nothing rather than guessing when nothing is configured', function () {
        // An unconfigured platform undercharges visibly rather than inventing a
        // fee nobody agreed — the same reasoning D19 applies to tax.
        expect(app(FeeRuleResolver::class)->registrationFee(feeTestPackage(), Currency::BDT)->minorUnits)
            ->toBe(0);
    });
});

describe('the activation quote', function () {
    it('bills the resolved registration fee beside the package fee', function () {
        // §9: Total = Registration Fee + Package Fee.
        feeTestRule(100000);
        $package = feeTestPackage();

        $quote = app(CalculateActivationQuote::class)->handle($package);

        expect($quote->amountFor(AllocationType::RegistrationFee)->minorUnits)->toBe(100000)
            ->and($quote->amountFor(AllocationType::PackageFee)->minorUnits)->toBe(500000)
            ->and($quote->total()->minorUnits)->toBe(600000);
    });

    it('reproduces an older quote at the older price', function () {
        feeTestRule(100000, from: '-90 days', until: '-30 days');
        feeTestRule(150000, from: '-30 days');

        $package = feeTestPackage();

        $then = app(CalculateActivationQuote::class)->handle(
            $package,
            at: CarbonImmutable::instance(now())->subDays(60),
        );

        expect($then->total()->minorUnits)->toBe(600000);
    });
});

describe('managing rules', function () {
    it('adds a rule and records what was set', function () {
        $rule = app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::of(120000, Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
            note: 'Approved at the January pricing review.',
        );

        expect($rule->isInForce())->toBeTrue()
            ->and($rule->amount_minor->minorUnits)->toBe(120000);
    });

    it('refuses a window that overlaps one already open', function () {
        /*
         * Two rules in force at the same level would make the price depend on
         * which row the resolver read first — predictable, but not something
         * anybody chose.
         */
        feeTestRule(100000);

        expect(fn () => app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::of(120000, Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('allows a package rule to overlap the global one', function () {
        // That is what "more specific" means.
        $package = feeTestPackage();
        feeTestRule(100000);

        $scoped = app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::of(25000, Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
            packageId: $package->id,
        );

        expect($scoped->isInForce())->toBeTrue();
    });

    it('closes a rule by dating it rather than deleting it', function () {
        // A rule a payment was priced against is part of that payment's story.
        $rule = feeTestRule(100000, from: '-10 days');

        app(ManageFeeRules::class)->close($this->manager, $rule);

        expect($rule->refresh()->is_active)->toBeFalse()
            ->and($rule->effective_until)->not->toBeNull()
            ->and(FeeRule::query()->count())->toBe(1);
    });

    it('refuses a rule that ends before it begins', function () {
        expect(fn () => app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::of(120000, Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
            effectiveUntil: CarbonImmutable::instance(now())->subDay(),
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('the billing screen', function () {
    it('lists the rules and their windows', function () {
        feeTestRule(100000);

        $this->actingAs($this->manager)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->has('fee_rules', 1)
                ->where('fee_rules.0.in_force', true)
                ->where('can.manage', true),
            );
    });

    it('is closed to somebody without the payment permission', function () {
        // Writing a plan and pricing the fee charged alongside it are different
        // jobs: a Package Manager cannot move the registration fee.
        $packageManager = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($packageManager)
            ->get(route('admin.billing.index'))
            ->assertForbidden();
    });

    it('turns an overlapping window into a form error', function () {
        feeTestRule(100000);

        $this->actingAs($this->manager)
            ->post(route('admin.billing.fee-rules.store'), [
                'fee_type' => FeeType::Registration->value,
                'amount_minor' => 120000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('effective_from');
    });

    it('refuses a write from somebody who may only read', function () {
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $this->actingAs($viewer)
            ->post(route('admin.billing.fee-rules.store'), [
                'fee_type' => FeeType::Registration->value,
                'amount_minor' => 120000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertForbidden();

        expect(FeeRule::query()->count())->toBe(0);
    });
});
