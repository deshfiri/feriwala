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

function feeTestPackage(?string $registrationFee = null): Package
{
    return Package::create([
        'slug' => 'fee-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'currency_code' => 'BDT',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'registration_fee' => $registrationFee === null ? null : Money::fromDecimal($registrationFee, Currency::BDT),
        'validity_days' => 365,
        'required_deposit' => Money::zero(Currency::BDT),
        'minimum_balance' => Money::zero(Currency::BDT),
        'is_active' => true,
        'is_public' => true,
    ])->refresh()->load(['features', 'charges']);
}

function feeTestRule(
    string $amount,
    string $from = '-1 day',
    ?string $until = null,
    ?int $packageId = null,
): FeeRule {
    return FeeRule::create([
        'fee_type' => FeeType::Registration,
        'package_id' => $packageId,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal($amount, Currency::BDT),
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
        feeTestRule('1000.00');

        $fee = app(FeeRuleResolver::class)
            ->registrationFee(feeTestPackage(), Currency::BDT);

        expect($fee->toDecimal())->toBe('1000.00');
    });

    it('lets a package rule beat the global one', function () {
        // Specificity, not recency: a newer global price must not quietly
        // override a deal struck for one plan.
        $package = feeTestPackage();

        feeTestRule('1000.00');
        feeTestRule('250.00', packageId: $package->id);

        $fee = app(FeeRuleResolver::class)->registrationFee($package, Currency::BDT);

        expect($fee->toDecimal())->toBe('250.00');
    });

    it('keeps the package column as the per-package override', function () {
        // It is edited where the rest of the package is, and moving it would
        // mean two screens that can disagree.
        feeTestRule('1000.00');

        $fee = app(FeeRuleResolver::class)
            ->registrationFee(feeTestPackage(registrationFee: '400.00'), Currency::BDT);

        expect($fee->toDecimal())->toBe('400.00');
    });

    it('resolves against the moment of the charge, not today', function () {
        /*
         * A reissued quote or a recalculated invoice has to reproduce the
         * arithmetic it did originally.
         */
        feeTestRule('1000.00', from: '-90 days', until: '-30 days');
        feeTestRule('1500.00', from: '-30 days');

        $resolver = app(FeeRuleResolver::class);
        $package = feeTestPackage();

        expect($resolver->registrationFee($package, Currency::BDT)->toDecimal())->toBe('1500.00')
            ->and($resolver->registrationFee(
                $package,
                Currency::BDT,
                CarbonImmutable::instance(now())->subDays(60),
            )->toDecimal())->toBe('1000.00');
    });

    it('ignores a rule that is closed or not yet open', function () {
        $package = feeTestPackage();

        feeTestRule('1000.00')->forceFill(['is_active' => false])->save();
        feeTestRule('2000.00', from: '+10 days');

        expect(app(FeeRuleResolver::class)->registrationFee($package, Currency::BDT)->toDecimal())
            ->toBe('0.00');
    });

    it('charges nothing rather than guessing when nothing is configured', function () {
        // An unconfigured platform undercharges visibly rather than inventing a
        // fee nobody agreed — the same reasoning D19 applies to tax.
        expect(app(FeeRuleResolver::class)->registrationFee(feeTestPackage(), Currency::BDT)->toDecimal())
            ->toBe('0.00');
    });
});

describe('the activation quote', function () {
    it('bills the resolved registration fee beside the package fee', function () {
        // §9: Total = Registration Fee + Package Fee.
        feeTestRule('1000.00');
        $package = feeTestPackage();

        $quote = app(CalculateActivationQuote::class)->handle($package);

        expect($quote->amountFor(AllocationType::RegistrationFee)->toDecimal())->toBe('1000.00')
            ->and($quote->amountFor(AllocationType::PackageFee)->toDecimal())->toBe('5000.00')
            ->and($quote->total()->toDecimal())->toBe('6000.00');
    });

    it('reproduces an older quote at the older price', function () {
        feeTestRule('1000.00', from: '-90 days', until: '-30 days');
        feeTestRule('1500.00', from: '-30 days');

        $package = feeTestPackage();

        $then = app(CalculateActivationQuote::class)->handle(
            $package,
            at: CarbonImmutable::instance(now())->subDays(60),
        );

        expect($then->total()->toDecimal())->toBe('6000.00');
    });
});

describe('managing rules', function () {
    it('adds a rule and records what was set', function () {
        $rule = app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::fromDecimal('1200.00', Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
            note: 'Approved at the January pricing review.',
        );

        expect($rule->isInForce())->toBeTrue()
            ->and($rule->amount->toDecimal())->toBe('1200.00');
    });

    it('refuses a window that overlaps one already open', function () {
        /*
         * Two rules in force at the same level would make the price depend on
         * which row the resolver read first — predictable, but not something
         * anybody chose.
         */
        feeTestRule('1000.00');

        expect(fn () => app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::fromDecimal('1200.00', Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('allows a package rule to overlap the global one', function () {
        // That is what "more specific" means.
        $package = feeTestPackage();
        feeTestRule('1000.00');

        $scoped = app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::fromDecimal('250.00', Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
            packageId: $package->id,
        );

        expect($scoped->isInForce())->toBeTrue();
    });

    it('closes a rule by dating it rather than deleting it', function () {
        // A rule a payment was priced against is part of that payment's story.
        $rule = feeTestRule('1000.00', from: '-10 days');

        app(ManageFeeRules::class)->close($this->manager, $rule);

        expect($rule->refresh()->is_active)->toBeFalse()
            ->and($rule->effective_until)->not->toBeNull()
            ->and(FeeRule::query()->count())->toBe(1);
    });

    it('refuses a rule that ends before it begins', function () {
        expect(fn () => app(ManageFeeRules::class)->create(
            actor: $this->manager,
            type: FeeType::Registration,
            amount: Money::fromDecimal('1200.00', Currency::BDT),
            effectiveFrom: CarbonImmutable::instance(now()),
            effectiveUntil: CarbonImmutable::instance(now())->subDay(),
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('the billing screen', function () {
    it('lists the rules and their windows', function () {
        feeTestRule('1000.00');

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
        feeTestRule('1000.00');

        $this->actingAs($this->manager)
            ->post(route('admin.billing.fee-rules.store'), [
                'fee_type' => FeeType::Registration->value,
                'amount' => '1200.00',
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('effective_from');
    });

    it('refuses a write from somebody who may only read', function () {
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $this->actingAs($viewer)
            ->post(route('admin.billing.fee-rules.store'), [
                'fee_type' => FeeType::Registration->value,
                'amount' => '1200.00',
                'effective_from' => now()->toDateString(),
            ])
            ->assertForbidden();

        expect(FeeRule::query()->count())->toBe(0);
    });
});
