<?php

use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Money;

beforeEach(function () {
    $this->settings = app(SettingsRepository::class);

    $this->settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $this->settings->define('billing.tax_rate_percent', 'billing', SettingType::Decimal, '0');
    $this->settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
});

function quoteFor(array $packageAttributes = [], ...$args)
{
    $package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth-'.uniqid(),
        'fee_minor' => 500000,
        ...$packageAttributes,
    ]);

    return app(CalculateActivationQuote::class)->handle($package, ...$args);
}

describe('the combined activation payment (§5.1)', function () {
    it('adds the registration fee and the package fee', function () {
        $quote = quoteFor();

        expect($quote->amountFor(AllocationType::RegistrationFee)->minorUnits)->toBe(100000)
            ->and($quote->amountFor(AllocationType::PackageFee)->minorUnits)->toBe(500000)
            ->and($quote->total()->minorUnits)->toBe(600000);
    });

    it('keeps the two fees as separate lines, never merged', function () {
        // §5.1: they must appear separately in the breakdown, invoice, and
        // ledger even though they are paid together.
        $types = array_map(fn ($line) => $line->type, quoteFor()->lines);

        expect($types)->toContain(AllocationType::RegistrationFee)
            ->and($types)->toContain(AllocationType::PackageFee);
    });

    it('lets a package override the global registration fee', function () {
        $quote = quoteFor(['registration_fee_minor' => 50000]);

        expect($quote->amountFor(AllocationType::RegistrationFee)->minorUnits)->toBe(50000)
            ->and($quote->total()->minorUnits)->toBe(550000);
    });

    it('omits a zero registration fee rather than showing an empty line', function () {
        $this->settings->set('billing.registration_fee', 0);

        expect(quoteFor()->amountFor(AllocationType::RegistrationFee)->isZero())->toBeTrue()
            ->and(quoteFor()->lines)->toHaveCount(1);
    });
});

describe('discount', function () {
    it('comes off the total', function () {
        $quote = quoteFor([], discount: Money::of(50000));

        expect($quote->total()->minorUnits)->toBe(550000);
    });

    it('is stored positive with a deduction flag, not as a negative', function () {
        // So a report summing "discount given" never has to flip a sign.
        $quote = quoteFor([], discount: Money::of(50000));

        expect($quote->amountFor(AllocationType::Discount)->minorUnits)->toBe(50000)
            ->and(AllocationType::Discount->isDeduction())->toBeTrue();
    });

    it('is capped at the fees so the total can never go negative', function () {
        // A generous coupon must not turn a sale into a payout.
        $quote = quoteFor([], discount: Money::of(9_999_999));

        expect($quote->total()->minorUnits)->toBe(0)
            ->and($quote->total()->isNegative())->toBeFalse();
    });

    it('makes a fully discounted activation non-payable', function () {
        // A promotional or administratively granted package must not be sent
        // to a gateway for zero.
        $quote = quoteFor([], discount: Money::of(600000));

        expect($quote->isPayable())->toBeFalse();
    });
});

describe('tax', function () {
    beforeEach(function () {
        $this->settings->set('billing.tax_rate_percent', '15');
    });

    it('is charged on the fees', function () {
        $quote = quoteFor();

        // 15% of 6,000.00
        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(90000)
            ->and($quote->total()->minorUnits)->toBe(690000);
    });

    it('is charged after the discount, not before', function () {
        // Taxing before the discount would overcharge the customer.
        $quote = quoteFor([], discount: Money::of(100000));

        // 15% of (6,000 - 1,000) = 750.00
        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(75000)
            ->and($quote->total()->minorUnits)->toBe(575000);
    });

    it('is not charged on a wallet deposit', function () {
        // A deposit is the partner's own money going onto their account —
        // taxing it would be charging VAT on someone's savings.
        $quote = quoteFor([], walletDeposit: Money::of(1000000));

        expect($quote->amountFor(AllocationType::Tax)->minorUnits)->toBe(90000)
            ->and(AllocationType::WalletDeposit->isTaxable())->toBeFalse();
    });
});

describe('wallet deposit', function () {
    it('is added to the total', function () {
        $quote = quoteFor([], walletDeposit: Money::of(1000000));

        expect($quote->amountFor(AllocationType::WalletDeposit)->minorUnits)->toBe(1000000)
            ->and($quote->total()->minorUnits)->toBe(1600000);
    });

    it('is not counted as revenue', function () {
        // It stays the partner's money. Counting it as revenue would overstate
        // earnings and understate what Feriwala owes.
        $quote = quoteFor([], walletDeposit: Money::of(1000000));

        expect($quote->revenue()->minorUnits)->toBe(600000)
            ->and($quote->total()->minorUnits)->toBe(1600000);
    });
});

describe('gateway charge', function () {
    it('is applied to what is actually transacted', function () {
        $this->settings->set('billing.gateway_charge_percent', '2');

        $quote = quoteFor([], walletDeposit: Money::of(400000));

        // 2% of (6,000 + 4,000)
        expect($quote->amountFor(AllocationType::GatewayCharge)->minorUnits)->toBe(20000)
            ->and($quote->total()->minorUnits)->toBe(1020000);
    });

    it('is not applied when nothing is payable', function () {
        $this->settings->set('billing.gateway_charge_percent', '2');

        $quote = quoteFor([], discount: Money::of(600000));

        expect($quote->amountFor(AllocationType::GatewayCharge)->isZero())->toBeTrue();
    });
});

describe('the full breakdown', function () {
    it('itemises every component §9 requires', function () {
        $this->settings->set('billing.tax_rate_percent', '15');
        $this->settings->set('billing.gateway_charge_percent', '2');

        $quote = quoteFor([], walletDeposit: Money::of(1000000), discount: Money::of(50000));

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
        $this->settings->set('billing.tax_rate_percent', '15');

        $quote = quoteFor([], walletDeposit: Money::of(1000000), discount: Money::of(50000));

        $sum = Money::zero();

        foreach ($quote->lines as $line) {
            $sum = $sum->plus($line->signedAmount());
        }

        expect($sum->minorUnits)->toBe($quote->total()->minorUnits);
    });

    it('serialises for the client without exposing arithmetic', function () {
        $array = quoteFor()->toArray();

        expect($array)->toHaveKeys(['lines', 'subtotal', 'total', 'currency', 'is_payable'])
            ->and($array['total'])->toHaveKey('formatted');
    });
});
