<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Tax\Actions\ManageTaxRules;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Applying tax rules at checkout (P1-47, §9, D19).
 *
 * The rule that matters most here is a negative one: a rule that is inactive,
 * outside its window, or naming a code with no rate must never quietly become a
 * charge — and must never quietly become *no* charge either, without saying so.
 */

function taxSettingsPackage(): Package
{
    return Package::create([
        'slug' => 'tax-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'fee_minor' => 500000,
        'registration_fee_minor' => 100000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
});

describe('never applying a rule that does not stand', function () {
    it('charges nothing for a withdrawn rule', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->inactive()->create();

        $quote = app(CalculateActivationQuote::class)->handle(taxSettingsPackage());

        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(0)
            ->and($quote->total()->minorUnits)->toBe(600000);
    });

    it('charges nothing for a rule that has not started', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->startingAt(now()->addWeek())->create();

        $quote = app(CalculateActivationQuote::class)->handle(taxSettingsPackage());

        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(0);
    });

    it('charges nothing for a rule whose window has closed', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->endedAt(now()->subDay())->create();

        $quote = app(CalculateActivationQuote::class)->handle(taxSettingsPackage());

        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(0);
    });

    it('says so when a live rule names a rate that is no longer in force', function () {
        /*
         * D19 forbids assuming a statutory rate, so the charge is nothing. But a
         * rule silently taxing nothing looks exactly like a rule that works, and
         * an administrator would find out from a customer.
         */
        Log::spy();

        TaxRate::factory()->inactive()->create();
        TaxRule::factory()->create();

        $quote = app(CalculateActivationQuote::class)->handle(taxSettingsPackage());

        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'no rate in force'));
    });

    it('resolves the rate that was in force when the charge was made', function () {
        // A reissued quote reproduces its own arithmetic rather than today's.
        TaxRate::factory()->percent(15)->endedAt(now()->subDays(30))->create();
        TaxRate::factory()->percent(12)->startingAt(now()->subDays(30))->create();
        TaxRule::factory()->create();

        $quotes = app(CalculateActivationQuote::class);
        $package = taxSettingsPackage();

        $now = $quotes->handle($package);
        $then = $quotes->handle($package, at: CarbonImmutable::instance(now())->subDays(60));

        expect($now->amountFor(AllocationType::Tax)->minorUnits)->toBe(72000)
            ->and($then->amountFor(AllocationType::Tax)->minorUnits)->toBe(90000);
    });
});

describe('managing rates', function () {
    it('adds a rate and puts it in force', function () {
        $rate = app(ManageTaxRules::class)->createRate(
            actor: $this->manager,
            code: 'VAT-Standard',
            name: 'VAT',
            basisPoints: 1500,
            effectiveFrom: CarbonImmutable::instance(now())->subDay(),
        );

        // Codes are normalised: a rule points at the string, and two casings
        // would be two codes.
        expect($rate->code)->toBe('vat-standard')
            ->and($rate->appliesAt(CarbonImmutable::instance(now())))->toBeTrue();
    });

    it('refuses a second open window for the same code', function () {
        // Two rates in force under one code would make the percentage depend on
        // which row was read first.
        TaxRate::factory()->create();

        expect(fn () => app(ManageTaxRules::class)->createRate(
            actor: $this->manager,
            code: 'vat-standard',
            name: 'VAT',
            basisPoints: 1200,
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a rate above one hundred percent', function () {
        expect(fn () => app(ManageTaxRules::class)->createRate(
            actor: $this->manager,
            code: 'vat-absurd',
            name: 'VAT',
            basisPoints: 10001,
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('closes a rate by dating it rather than deleting it', function () {
        // Invoices were priced against it; the row is part of their story.
        $rate = TaxRate::factory()->create();

        app(ManageTaxRules::class)->closeRate($this->manager, $rate);

        expect($rate->refresh()->is_active)->toBeFalse()
            ->and($rate->effective_until)->not->toBeNull()
            ->and(TaxRate::query()->count())->toBe(1);
    });
});

describe('managing rules', function () {
    it('refuses a rule naming a code that does not exist', function () {
        /*
         * The guard against a rule that is silently no rule. It would resolve to
         * nothing for ever while sitting on the settings screen looking live.
         */
        expect(fn () => app(ManageTaxRules::class)->createRule(
            actor: $this->manager,
            scope: TaxScope::Everything,
            scopeValue: null,
            taxCode: 'vat-imaginary',
            mode: TaxMode::Exclusive,
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a targeted rule that does not say what it targets', function () {
        TaxRate::factory()->create();

        expect(fn () => app(ManageTaxRules::class)->createRule(
            actor: $this->manager,
            scope: TaxScope::Fee,
            scopeValue: null,
            taxCode: 'vat-standard',
            mode: TaxMode::Exclusive,
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a rule that would tie with one already open', function () {
        // Same scope, same target, same priority, overlapping window: nothing
        // separates them but row order.
        TaxRate::factory()->create();
        TaxRule::factory()->create();

        expect(fn () => app(ManageTaxRules::class)->createRule(
            actor: $this->manager,
            scope: TaxScope::Everything,
            scopeValue: null,
            taxCode: 'vat-standard',
            mode: TaxMode::Exclusive,
            effectiveFrom: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('allows a more specific rule beside the catch-all', function () {
        // That is what "more specific" means.
        TaxRate::factory()->create();
        TaxRate::factory()->code('vat-reduced')->percent(5)->create();
        TaxRule::factory()->create();

        $rule = app(ManageTaxRules::class)->createRule(
            actor: $this->manager,
            scope: TaxScope::Fee,
            scopeValue: AllocationType::RegistrationFee->value,
            taxCode: 'vat-reduced',
            mode: TaxMode::Exclusive,
            effectiveFrom: CarbonImmutable::instance(now())->subDay(),
        );

        expect($rule->isInForce())->toBeTrue();

        $quote = app(CalculateActivationQuote::class)->handle(taxSettingsPackage());

        // Registration at 5% = 5,000; package at 15% = 75,000.
        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(80000);
    });

    it('closes a rule by dating it rather than deleting it', function () {
        $rule = TaxRule::factory()->create();

        app(ManageTaxRules::class)->closeRule($this->manager, $rule);

        expect($rule->refresh()->is_active)->toBeFalse()
            ->and($rule->effective_until)->not->toBeNull()
            ->and(TaxRule::query()->count())->toBe(1);
    });
});

describe('the billing screen', function () {
    it('lists rates and rules with what each charges today', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->create();

        $this->actingAs($this->manager)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->has('tax_rates', 1)
                ->where('tax_rates.0.percent', '15%')
                ->has('tax_rules', 1)
                ->where('tax_rules.0.rate', 'VAT (15%)')
                ->where('tax_rules.0.in_force', true),
            );
    });

    it('shows a live rule with no rate in force as having none', function () {
        // The screen half of the same guard: it must be visibly broken.
        TaxRate::factory()->inactive()->create();
        TaxRule::factory()->create();

        $this->actingAs($this->manager)
            ->get(route('admin.billing.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tax_rules.0.rate', null)
                ->where('tax_rules.0.in_force', true),
            );
    });

    it('turns an unknown code into a form error', function () {
        TaxRate::factory()->create();

        $this->actingAs($this->manager)
            ->post(route('admin.billing.tax-rules.store'), [
                'scope' => TaxScope::Everything->value,
                'tax_code' => 'vat-imaginary',
                'mode' => TaxMode::Exclusive->value,
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('tax_code');

        expect(TaxRule::query()->count())->toBe(0);
    });

    it('adds a rate through the form', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.billing.tax-rates.store'), [
                'code' => 'vat-standard',
                'name' => 'VAT',
                'rate_basis_points' => 1500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertRedirect();

        expect(TaxRate::query()->count())->toBe(1);
    });

    it('refuses a write from somebody who may only read', function () {
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $this->actingAs($viewer)
            ->post(route('admin.billing.tax-rates.store'), [
                'code' => 'vat-standard',
                'name' => 'VAT',
                'rate_basis_points' => 1500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertForbidden();

        expect(TaxRate::query()->count())->toBe(0);
    });
});

describe('the applicant checkout', function () {
    beforeEach(function () {
        $settings = app(SettingsRepository::class);
        $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
        $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');

        $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
        $this->applicant = $this->account->owner;

        $this->package = Package::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'fee_minor' => 500000,
            'validity_days' => 365,
        ]);

        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    });

    it('itemises the tax per rate, not just as a total', function () {
        // A rolled-up figure cannot answer "at what rate, on what" — which is
        // the question anybody filing a return has (D19).
        TaxRate::factory()->create();
        TaxRule::factory()->create();

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/checkout')
                ->has('quote.tax', 1)
                ->where('quote.tax.0.label', 'VAT (15%)')
                ->where('quote.tax.0.net.minor_units', 600000)
                ->where('quote.tax.0.tax.minor_units', 90000)
                ->where('quote.total.minor_units', 690000),
            );
    });

    it('shows no tax at all while nothing is configured', function () {
        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('quote.tax', 0)
                ->where('quote.total.minor_units', 600000),
            );
    });

    it('reports inclusive tax without adding it to the total', function () {
        // It is already inside the fees. Adding it would charge it twice.
        TaxRate::factory()->create();
        TaxRule::factory()->inclusive()->create();

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('quote.tax', 1)
                ->where('quote.total.minor_units', 600000)
                ->where('quote.tax_included.minor_units', 78260),
            );
    });
});
