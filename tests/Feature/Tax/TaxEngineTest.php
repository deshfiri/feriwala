<?php

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\Models\TaxExemption;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\TaxEngine;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/*
 * The configurable tax engine (P1-69 to P1-72, D19).
 *
 * The rules that matter here are the ones that go wrong quietly: a rate that
 * keeps applying after it was superseded, an exemption that outlives its
 * certificate, inclusive arithmetic whose parts no longer sum to the price the
 * customer was shown.
 */

function taxTestEngine(): TaxEngine
{
    return app(TaxEngine::class);
}

/** A standard rate with a rule covering everything. */
function taxTestStandardRate(float $percent = 15.0): TaxRate
{
    $rate = TaxRate::factory()->percent($percent)->create();
    TaxRule::factory()->create();

    return $rate;
}

describe('resolution', function () {
    it('charges nothing at all when nothing is configured', function () {
        // D19 forbids assuming a statutory rate. An unconfigured system must
        // undercharge visibly rather than put 15% nobody agreed onto invoices.
        $charge = taxTestEngine()->charge(
            Money::fromDecimal('5000.00', Currency::BDT),
            AllocationType::PackageFee,
        );

        expect($charge->tax->toDecimal())->toBe('0.00')
            ->and($charge->code)->toBe('');
    });

    it('charges nothing when a rule points at a code with no rate', function () {
        TaxRule::factory()->usingCode('vat-nonexistent')->create();

        expect(taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::PackageFee)->tax->toDecimal())
            ->toBe('0.00');
    });

    it('applies the catch-all rule when no targeted one matches', function () {
        taxTestStandardRate();

        $charge = taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::PackageFee);

        expect($charge->tax->toDecimal())->toBe('750.00')
            ->and($charge->code)->toBe('vat-standard');
    });

    it('lets a fee rule beat the catch-all', function () {
        taxTestStandardRate();
        TaxRate::factory()->code('vat-reduced')->percent(5)->create();
        TaxRule::factory()->forFee(AllocationType::RegistrationFee)->usingCode('vat-reduced')->create();

        $registration = taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::RegistrationFee);
        $package = taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee);

        expect($registration->tax->toDecimal())->toBe('50.00')
            ->and($package->tax->toDecimal())->toBe('150.00');
    });

    it('does not let a high-priority broad rule override a targeted one', function () {
        /*
         * The failure this guards: priority crossing specificity. An
         * administrator raising the priority of a catch-all would silently
         * override every targeted rule beneath it, and nobody would find out
         * until an invoice was wrong.
         */
        taxTestStandardRate();
        TaxRule::factory()->priority(999)->create();

        TaxRate::factory()->code('vat-reduced')->percent(5)->create();
        TaxRule::factory()->forFee(AllocationType::PackageFee)->usingCode('vat-reduced')->priority(0)->create();

        expect(taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee)->tax->toDecimal())
            ->toBe('50.00');
    });

    it('breaks ties within one level by priority, newest first', function () {
        TaxRate::factory()->code('vat-a')->percent(10)->create();
        TaxRate::factory()->code('vat-b')->percent(20)->create();

        TaxRule::factory()->forFee(AllocationType::PackageFee)->usingCode('vat-a')->priority(1)->create();
        TaxRule::factory()->forFee(AllocationType::PackageFee)->usingCode('vat-b')->priority(9)->create();

        expect(taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee)->code)
            ->toBe('vat-b');
    });

    it('ignores an inactive rule and an inactive rate', function (string $inactive) {
        $rate = TaxRate::factory()->percent(15);
        $rule = TaxRule::factory();

        ($inactive === 'rate' ? $rate->inactive() : $rate)->create();
        ($inactive === 'rule' ? $rule->inactive() : $rule)->create();

        expect(taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee)->tax->toDecimal())
            ->toBe('0.00');
    })->with(['rate', 'rule']);

    it('never taxes a component that is not taxable', function () {
        // A deposit is the partner's own money going onto their wallet. Taxing
        // it would be charging VAT on somebody's savings.
        taxTestStandardRate();

        expect(taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::WalletDeposit)->tax->toDecimal())
            ->toBe('0.00');
    });
});

describe('effective dates', function () {
    it('uses the rate that was in force when the charge was made', function () {
        /*
         * The reason a rate is versioned rather than edited. A refund or a
         * reissued invoice must reproduce the original arithmetic; resolving
         * against today's rate would make an old invoice stop adding up.
         */
        $lastYear = CarbonImmutable::parse('2025-01-01');
        $thisYear = CarbonImmutable::parse('2026-01-01');

        TaxRate::factory()->percent(15)
            ->startingAt($lastYear->subYear())->endedAt($thisYear)->create();

        TaxRate::factory()->percent(12)->startingAt($thisYear)->create();

        TaxRule::factory()->startingAt($lastYear->subYear())->create();

        $engine = taxTestEngine();

        expect($engine->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee, at: $lastYear)->tax->toDecimal())
            ->toBe('150.00')
            ->and($engine->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee, at: $thisYear)->tax->toDecimal())
            ->toBe('120.00');
    });

    it('ignores a rule that has not started or has ended', function (string $when) {
        taxTestStandardRate();

        TaxRate::factory()->code('vat-special')->percent(50)->create();

        $rule = TaxRule::factory()->forFee(AllocationType::PackageFee)->usingCode('vat-special');

        $rule = $when === 'future'
            ? $rule->startingAt(now()->addMonth())
            : $rule->startingAt(now()->subYear())->endedAt(now()->subDay());

        $rule->create();

        // Falls through to the catch-all rather than picking the out-of-window
        // rule.
        expect(taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), AllocationType::PackageFee)->tax->toDecimal())
            ->toBe('150.00');
    })->with(['future', 'past']);
});

describe('exemption', function () {
    it('charges nothing to an exempt account', function () {
        taxTestStandardRate();
        $account = testBusinessAccount();
        TaxExemption::factory()->create(['business_account_id' => $account->id]);

        expect(taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::PackageFee, account: $account)->tax->toDecimal())
            ->toBe('0.00');
    });

    it('stops honouring an exemption that has expired, been revoked, or not started', function (string $state) {
        // An expired certificate must stop working on its own. A boolean on the
        // account would still be true, and nobody would notice for a year.
        taxTestStandardRate();
        $account = testBusinessAccount();

        TaxExemption::factory()->{$state}()->create(['business_account_id' => $account->id]);

        expect(taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::PackageFee, account: $account)->tax->toDecimal())
            ->toBe('750.00');
    })->with(['expired', 'revoked', 'future']);

    it('does not leak one account\'s exemption to another', function () {
        taxTestStandardRate();
        $exempt = testBusinessAccount();
        $other = testBusinessAccount();

        TaxExemption::factory()->create(['business_account_id' => $exempt->id]);

        expect(taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::PackageFee, account: $other)->tax->toDecimal())
            ->toBe('750.00');
    });
});

describe('arithmetic', function () {
    it('adds tax on top under the default exclusive mode', function () {
        $charge = taxTestEngine()->apply(
            Money::fromDecimal('5000.00', Currency::BDT),
            TaxRate::factory()->percent(15)->create(),
            TaxMode::Exclusive,
        );

        expect($charge->net->toDecimal())->toBe('5000.00')
            ->and($charge->tax->toDecimal())->toBe('750.00')
            ->and($charge->gross()->toDecimal())->toBe('5750.00');
    });

    it('takes tax out of an inclusive price without changing the price', function () {
        $charge = taxTestEngine()->apply(
            Money::fromDecimal('5750.00', Currency::BDT),
            TaxRate::factory()->percent(15)->create(),
            TaxMode::Inclusive,
        );

        expect($charge->net->toDecimal())->toBe('5000.00')
            ->and($charge->tax->toDecimal())->toBe('750.00')
            ->and($charge->gross()->toDecimal())->toBe('5750.00');
    });

    it('keeps net plus tax exactly equal to an inclusive price that does not divide evenly', function (string $gross) {
        /*
         * The bug this guards: rounding the net and the tax independently. Both
         * are then correct to the currency's own scale on their own, and their
         * sum is one hundredth of a Taka away from the price the customer was
         * shown — which is a reconciliation failure, not a rounding nicety.
         */
        $charge = taxTestEngine()->apply(
            Money::fromDecimal($gross, Currency::BDT),
            TaxRate::factory()->percent(15)->create(),
            TaxMode::Inclusive,
        );

        expect($charge->gross()->toDecimal())->toBe($gross);
    })->with(['0.01', '0.07', '0.99', '3.33', '10.01', '123.45', '999.99', '1234.57']);

    it('handles a fractional rate without a float creeping in', function () {
        // 7.5% is 750 basis points. Held as a float it is 0.07500000000000001,
        // and that lands in a ledger that must reconcile to the currency's own
        // scale.
        $rate = TaxRate::factory()->percent(7.5)->create();

        expect($rate->rate_basis_points)->toBe(750)
            ->and($rate->formattedPercent())->toBe('7.5%')
            ->and(taxTestEngine()->apply(Money::fromDecimal('1000.00', Currency::BDT), $rate, TaxMode::Exclusive)->tax->toDecimal())
            ->toBe('75.00');
    });

    it('charges nothing at a zero rate', function () {
        TaxRate::factory()->zeroRated()->create();
        TaxRule::factory()->usingCode('vat-zero')->create();

        expect(taxTestEngine()->charge(Money::fromDecimal('5000.00', Currency::BDT), AllocationType::PackageFee)->isZero())
            ->toBeTrue();
    });
});

describe('the breakdown', function () {
    it('merges charges at the same rate into one invoice line', function () {
        // A VAT invoice reads one line per rate, not one per fee.
        $rate = TaxRate::factory()->percent(15)->create();
        $engine = taxTestEngine();

        $breakdown = TaxBreakdown::of([
            $engine->apply(Money::fromDecimal('1000.00', Currency::BDT), $rate, TaxMode::Exclusive),
            $engine->apply(Money::fromDecimal('4000.00', Currency::BDT), $rate, TaxMode::Exclusive),
        ], Currency::base());

        expect($breakdown->charges)->toHaveCount(1)
            ->and($breakdown->taxableTotal()->toDecimal())->toBe('5000.00')
            ->and($breakdown->total()->toDecimal())->toBe('750.00');
    });

    it('keeps different rates on separate lines', function () {
        $engine = taxTestEngine();

        $breakdown = TaxBreakdown::of([
            $engine->apply(Money::fromDecimal('1000.00', Currency::BDT), TaxRate::factory()->percent(15)->create(), TaxMode::Exclusive),
            $engine->apply(Money::fromDecimal('1000.00', Currency::BDT), TaxRate::factory()->code('vat-reduced')->percent(5)->create(), TaxMode::Exclusive),
        ], Currency::base());

        expect($breakdown->charges)->toHaveCount(2)
            ->and($breakdown->total()->toDecimal())->toBe('200.00');
    });

    it('counts added tax and included tax apart', function () {
        // Adding inclusive tax to a total would charge it twice: it is already
        // inside the price. It still has to appear on the invoice.
        $rate = TaxRate::factory()->percent(15)->create();
        $engine = taxTestEngine();

        $breakdown = TaxBreakdown::of([
            $engine->apply(Money::fromDecimal('1000.00', Currency::BDT), $rate, TaxMode::Exclusive),
            $engine->apply(Money::fromDecimal('1150.00', Currency::BDT), $rate, TaxMode::Inclusive),
        ], Currency::base());

        expect($breakdown->addedTotal()->toDecimal())->toBe('150.00')
            ->and($breakdown->includedTotal()->toDecimal())->toBe('150.00')
            ->and($breakdown->total()->toDecimal())->toBe('300.00');
    });

    it('drops zero-rated lines rather than listing them at nothing', function () {
        $engine = taxTestEngine();

        $breakdown = TaxBreakdown::of([
            $engine->apply(Money::fromDecimal('1000.00', Currency::BDT), TaxRate::factory()->zeroRated()->create(), TaxMode::Exclusive),
        ], Currency::base());

        expect($breakdown->isEmpty())->toBeTrue();
    });
});

describe('rule scoping', function () {
    it('lets a product rule beat its category', function () {
        // A zero-rated book must not pick up the standard rate its category
        // carries.
        TaxRate::factory()->percent(15)->create();
        TaxRate::factory()->zeroRated()->create();

        TaxRule::factory()->forCategory('books')->create();
        TaxRule::factory()->forProduct('01JBOOKPUBLICID')->usingCode('vat-zero')->create();

        $engine = taxTestEngine();

        $book = $engine->charge(
            Money::fromDecimal('1000.00', Currency::BDT),
            scope: TaxScope::Product,
            scopeValue: '01JBOOKPUBLICID',
        );

        $otherBook = $engine->charge(
            Money::fromDecimal('1000.00', Currency::BDT),
            scope: TaxScope::Category,
            scopeValue: 'books',
        );

        expect($book->isZero())->toBeTrue()
            ->and($otherBook->tax->toDecimal())->toBe('150.00');
    });

    it('matches a scope value regardless of case', function () {
        // A fee type, a product public id and a category slug arrive from
        // different places and only one of them is certain to be normalised.
        TaxRate::factory()->percent(15)->create();
        TaxRule::factory()->forCategory('Books')->create();

        expect(taxTestEngine()->charge(Money::fromDecimal('1000.00', Currency::BDT), scope: TaxScope::Category, scopeValue: 'BOOKS')->tax->toDecimal())
            ->toBe('150.00');
    });
});
