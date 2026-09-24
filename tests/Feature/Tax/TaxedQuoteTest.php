<?php

use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Package\Models\Package;
use App\Domain\Tax\Models\TaxExemption;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/*
 * The activation quote once the tax engine is behind it (P1-69 to P1-72, §9).
 *
 * The order of operations is what these protect: fees, then discount, then tax
 * on what remains, then the untaxed deposit, then the gateway charge. Taxing
 * before the discount overcharges; taxing the deposit charges VAT on somebody's
 * savings.
 */

function taxedQuoteTestPackage(string $fee = '5000.00', ?string $registration = '1000.00'): Package
{
    return Package::create([
        'slug' => 'taxed-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'fee' => Money::fromDecimal($fee, Currency::BDT),
        'registration_fee' => $registration === null ? null : Money::fromDecimal($registration, Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function taxedQuoteTestStandardRate(float $percent = 15.0): TaxRate
{
    $rate = TaxRate::factory()->percent($percent)->create();
    TaxRule::factory()->create();

    return $rate;
}

it('charges no tax while nothing is configured', function () {
    // D19: no statutory rate is assumed. Before an administrator configures
    // one, a quote must simply not carry tax.
    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage());

    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('0.00')
        ->and($quote->total()->toDecimal())->toBe('6000.00')
        ->and($quote->taxBreakdown()->isEmpty())->toBeTrue();
});

it('adds tax on the fees once a rule exists', function () {
    taxedQuoteTestStandardRate();

    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage());

    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('900.00')
        ->and($quote->total()->toDecimal())->toBe('6900.00');
});

it('taxes what is left after the discount, not before it', function () {
    // Taxing first would charge VAT on money the customer never paid.
    taxedQuoteTestStandardRate();

    $quote = app(CalculateActivationQuote::class)->handle(
        taxedQuoteTestPackage(),
        discount: Money::fromDecimal('1000.00', Currency::BDT),
    );

    // 6,000.00 fees - 1,000.00 discount = 5,000.00 taxable; 15% = 750.00.
    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('750.00')
        ->and($quote->total()->toDecimal())->toBe('5750.00');
});

it('apportions the discount across fees carrying different rates', function () {
    /*
     * The reason the discount is spread rather than pooled. With a 5% rate on
     * the registration fee and 15% on the package fee, taking the discount off
     * a single pool and taxing the remainder at one rate would be arithmetic
     * belonging to neither fee — and an invoice could not show it per rate.
     */
    TaxRate::factory()->percent(15)->create();
    TaxRate::factory()->code('vat-reduced')->percent(5)->create();
    TaxRule::factory()->create();
    TaxRule::factory()->forFee(AllocationType::RegistrationFee)->usingCode('vat-reduced')->create();

    $quote = app(CalculateActivationQuote::class)->handle(
        taxedQuoteTestPackage(),
        discount: Money::fromDecimal('600.00', Currency::BDT),
    );

    // 600.00 split 1,000.00:5,000.00 gives 100.00 and 500.00.
    // Registration: 900.00 at 5% = 45.00. Package: 4,500.00 at 15% = 675.00.
    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('720.00')
        ->and($quote->taxBreakdown()->charges)->toHaveCount(2);
});

it('never taxes the wallet deposit', function () {
    taxedQuoteTestStandardRate();

    $quote = app(CalculateActivationQuote::class)->handle(
        taxedQuoteTestPackage(),
        walletDeposit: Money::fromDecimal('2000.00', Currency::BDT),
    );

    // Tax is still only on the 6,000.00 of fees.
    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('900.00')
        ->and($quote->total()->toDecimal())->toBe('8900.00');
});

it('charges no tax to an exempt account', function () {
    taxedQuoteTestStandardRate();
    $account = testBusinessAccount();
    TaxExemption::factory()->create(['business_account_id' => $account->id]);

    $quote = app(CalculateActivationQuote::class)->handle(
        taxedQuoteTestPackage(),
        account: $account,
    );

    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('0.00')
        ->and($quote->total()->toDecimal())->toBe('6000.00');
});

it('does not add inclusive tax to the total', function () {
    // The fee already contains it. Adding it would charge it twice.
    TaxRate::factory()->percent(15)->create();
    TaxRule::factory()->inclusive()->create();

    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage());

    expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('0.00')
        ->and($quote->total()->toDecimal())->toBe('6000.00')
        /*
         * Still reported: the invoice must show it and the return still owes it.
         *
         * 782.60 rather than the 782.61 that extracting from a pooled 6,000.00
         * would give. Each fee is taxed on its own — they can carry different
         * rates — so each rounds on its own, and the hundredth of a Taka of
         * difference is the honest consequence of that rather than an error.
         * What must not move is the price: both fees still gross to exactly
         * what was quoted.
         */
        ->and($quote->taxBreakdown()->includedTotal()->toDecimal())->toBe('782.60');
});

it('names the rate on the tax line when there is only one', function () {
    taxedQuoteTestStandardRate();

    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage());

    $taxLine = collect($quote->lines)->firstWhere('type', AllocationType::Tax);

    expect($taxLine->label())->toBe('VAT (15%)');
});

it('does not name one rate on a line covering several', function () {
    // Naming one would be wrong about the others.
    TaxRate::factory()->percent(15)->create();
    TaxRate::factory()->code('vat-reduced')->percent(5)->create();
    TaxRule::factory()->create();
    TaxRule::factory()->forFee(AllocationType::RegistrationFee)->usingCode('vat-reduced')->create();

    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage());

    $taxLine = collect($quote->lines)->firstWhere('type', AllocationType::Tax);

    expect($taxLine->label())->toBe('VAT');
});

it('stores the per-rate breakdown on the payment', function () {
    // The invoice reads "VAT 15% on 50.00 — 7.50" per rate, and a return needs
    // the taxable base. Neither survives a rolled-up total.
    taxedQuoteTestStandardRate();
    $account = testBusinessAccount();

    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage(), account: $account);

    $payment = app(RecordPaymentFromQuote::class)->handle(
        $account,
        $quote,
        PaymentPurpose::Activation,
    );

    expect($payment->taxLines)->toHaveCount(1);

    $line = $payment->taxLines->first();

    expect($line->tax_code)->toBe('vat-standard')
        ->and($line->rate_basis_points)->toBe(1500)
        ->and($line->taxable_amount->toDecimal())->toBe('6000.00')
        ->and($line->tax_amount->toDecimal())->toBe('900.00')
        ->and($line->formattedRate())->toBe('15%');
});

it('keeps a stored tax line intact after the rate changes', function () {
    /*
     * The whole reason the rate and its basis points are copied onto the line
     * rather than referenced. An invoice whose arithmetic shifts a year later
     * is worse than one showing a superseded rate.
     */
    $rate = taxedQuoteTestStandardRate();
    $account = testBusinessAccount();

    $quote = app(CalculateActivationQuote::class)->handle(taxedQuoteTestPackage(), account: $account);
    $payment = app(RecordPaymentFromQuote::class)->handle($account, $quote, PaymentPurpose::Activation);

    $rate->forceFill(['effective_until' => now()])->save();
    TaxRate::factory()->percent(12)->startingAt(now())->create();

    expect($payment->fresh()->taxLines->first()->rate_basis_points)->toBe(1500);
});

it('still balances its allocations against the total', function () {
    // Reconciliation (§28.1): a payment whose parts no longer sum to its whole
    // means something wrote to one and not the other.
    taxedQuoteTestStandardRate();
    $account = testBusinessAccount();

    $quote = app(CalculateActivationQuote::class)->handle(
        taxedQuoteTestPackage(),
        walletDeposit: Money::fromDecimal('2000.00', Currency::BDT),
        discount: Money::fromDecimal('500.00', Currency::BDT),
        account: $account,
    );

    $payment = app(RecordPaymentFromQuote::class)->handle($account, $quote, PaymentPurpose::Activation);

    expect($payment->allocationsBalance())->toBeTrue();
});
