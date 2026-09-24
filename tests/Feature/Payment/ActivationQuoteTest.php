<?php

use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Support\Money\Currency;
use App\Support\Money\Money;

beforeEach(function () {
    $this->settings = app(SettingsRepository::class);

    $this->settings->define('billing.registration_fee', 'billing', SettingType::Money, '1000.00');
    $this->settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
});

function quoteFor(array $packageAttributes = [], ...$args)
{
    $package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth-'.uniqid(),
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        ...$packageAttributes,
    ]);

    return app(CalculateActivationQuote::class)->handle($package, ...$args);
}

/**
 * A standard rate on everything.
 *
 * Tax is configuration now, not a setting string (D19): a rate with a window,
 * and a rule saying what it applies to.
 */
function quoteTestStandardRate(float $percent = 15.0): void
{
    TaxRate::factory()->percent($percent)->create();
    TaxRule::factory()->create();
}

describe('the combined activation payment (§5.1)', function () {
    it('adds the registration fee and the package fee', function () {
        $quote = quoteFor();

        expect($quote->amountFor(AllocationType::RegistrationFee)->toDecimal())->toBe('1000.00')
            ->and($quote->amountFor(AllocationType::PackageFee)->toDecimal())->toBe('5000.00')
            ->and($quote->total()->toDecimal())->toBe('6000.00');
    });

    it('keeps the two fees as separate lines, never merged', function () {
        // §5.1: they must appear separately in the breakdown, invoice, and
        // ledger even though they are paid together.
        $types = array_map(fn ($line) => $line->type, quoteFor()->lines);

        expect($types)->toContain(AllocationType::RegistrationFee)
            ->and($types)->toContain(AllocationType::PackageFee);
    });

    it('lets a package override the global registration fee', function () {
        $quote = quoteFor(['registration_fee' => Money::fromDecimal('500.00', Currency::BDT)]);

        expect($quote->amountFor(AllocationType::RegistrationFee)->toDecimal())->toBe('500.00')
            ->and($quote->total()->toDecimal())->toBe('5500.00');
    });

    it('omits a zero registration fee rather than showing an empty line', function () {
        $this->settings->set('billing.registration_fee', '0.00');

        expect(quoteFor()->amountFor(AllocationType::RegistrationFee)->isZero())->toBeTrue()
            ->and(quoteFor()->lines)->toHaveCount(1);
    });
});

describe('discount', function () {
    it('comes off the total', function () {
        $quote = quoteFor([], discount: Money::fromDecimal('500.00'));

        expect($quote->total()->toDecimal())->toBe('5500.00');
    });

    it('is stored positive with a deduction flag, not as a negative', function () {
        // So a report summing "discount given" never has to flip a sign.
        $quote = quoteFor([], discount: Money::fromDecimal('500.00'));

        expect($quote->amountFor(AllocationType::Discount)->toDecimal())->toBe('500.00')
            ->and(AllocationType::Discount->isDeduction())->toBeTrue();
    });

    it('is capped at the fees so the total can never go negative', function () {
        // A generous coupon must not turn a sale into a payout.
        $quote = quoteFor([], discount: Money::fromDecimal('99999.99'));

        expect($quote->total()->isZero())->toBeTrue()
            ->and($quote->total()->isNegative())->toBeFalse();
    });

    it('makes a fully discounted activation non-payable', function () {
        // A promotional or administratively granted package must not be sent
        // to a gateway for zero.
        $quote = quoteFor([], discount: Money::fromDecimal('6000.00'));

        expect($quote->isPayable())->toBeFalse();
    });
});

describe('tax', function () {
    beforeEach(function () {
        quoteTestStandardRate();
    });

    it('is charged on the fees', function () {
        $quote = quoteFor();

        // 15% of 6,000.00
        expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('900.00')
            ->and($quote->total()->toDecimal())->toBe('6900.00');
    });

    it('is charged after the discount, not before', function () {
        // Taxing before the discount would overcharge the customer.
        $quote = quoteFor([], discount: Money::fromDecimal('1000.00'));

        // 15% of (6,000 - 1,000) = 750.00
        expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('750.00')
            ->and($quote->total()->toDecimal())->toBe('5750.00');
    });

    it('is not charged on a wallet deposit', function () {
        // A deposit is the partner's own money going onto their account —
        // taxing it would be charging VAT on someone's savings.
        $quote = quoteFor([], walletDeposit: Money::fromDecimal('10000.00'));

        expect($quote->amountFor(AllocationType::Tax)->toDecimal())->toBe('900.00')
            ->and(AllocationType::WalletDeposit->isTaxable())->toBeFalse();
    });
});

describe('wallet deposit', function () {
    it('is added to the total', function () {
        $quote = quoteFor([], walletDeposit: Money::fromDecimal('10000.00'));

        expect($quote->amountFor(AllocationType::WalletDeposit)->toDecimal())->toBe('10000.00')
            ->and($quote->total()->toDecimal())->toBe('16000.00');
    });

    it('is not counted as revenue', function () {
        // It stays the partner's money. Counting it as revenue would overstate
        // earnings and understate what Feriwala owes.
        $quote = quoteFor([], walletDeposit: Money::fromDecimal('10000.00'));

        expect($quote->revenue()->toDecimal())->toBe('6000.00')
            ->and($quote->total()->toDecimal())->toBe('16000.00');
    });
});

describe('gateway charge', function () {
    it('is applied to what is actually transacted', function () {
        $this->settings->set('billing.gateway_charge_percent', '2');

        $quote = quoteFor([], walletDeposit: Money::fromDecimal('4000.00'));

        // 2% of (6,000 + 4,000)
        expect($quote->amountFor(AllocationType::GatewayCharge)->toDecimal())->toBe('200.00')
            ->and($quote->total()->toDecimal())->toBe('10200.00');
    });

    it('is not applied when nothing is payable', function () {
        $this->settings->set('billing.gateway_charge_percent', '2');

        $quote = quoteFor([], discount: Money::fromDecimal('6000.00'));

        expect($quote->amountFor(AllocationType::GatewayCharge)->isZero())->toBeTrue();
    });
});

describe('the full breakdown', function () {
    it('itemises every component §9 requires', function () {
        quoteTestStandardRate();
        $this->settings->set('billing.gateway_charge_percent', '2');

        $quote = quoteFor([], walletDeposit: Money::fromDecimal('10000.00'), discount: Money::fromDecimal('500.00'));

        $types = array_map(fn ($line) => $line->type->value, $quote->lines);

        expect($types)->toBe([
            'registration_fee',
            'package_fee',
            'discount',
            'tax',
            'wallet_deposit',
            'gateway_charge',
        ]);
    });

    it('has a total equal to the sum of its signed lines', function () {
        quoteTestStandardRate();

        $quote = quoteFor([], walletDeposit: Money::fromDecimal('10000.00'), discount: Money::fromDecimal('500.00'));

        $sum = Money::zero();

        foreach ($quote->lines as $line) {
            $sum = $sum->plus($line->signedAmount());
        }

        expect($sum->toDecimal())->toBe($quote->total()->toDecimal());
    });

    it('serialises for the client without exposing arithmetic', function () {
        $array = quoteFor()->toArray();

        expect($array)->toHaveKeys(['lines', 'subtotal', 'total', 'currency', 'is_payable'])
            ->and($array['total'])->toHaveKey('formatted');
    });
});
