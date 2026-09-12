<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\DepositRefundability;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;

/*
 * What happens to a deposit afterwards (P2-18, §24.4).
 *
 * §24.4 lists six configurable things and they are not six values of one field.
 * Three answer "how much comes back"; three are separate conditions on the same
 * money. An account can have a fully refundable deposit that is also reserved
 * until cancellation and also spendable on services.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
});

/**
 * @param  array<string, mixed>  $terms
 */
function refundabilityWallet(array $terms = [], int $credit = 500000): Wallet
{
    DepositRule::create([
        'scope' => RuleScope::Global,
        'required_initial_deposit_minor' => 300000,
        'minimum_balance_minor' => 0,
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
        ...$terms,
    ]);

    $wallet = app(OpenWallet::class)->handle(test()->account);

    app(WalletService::class)->credit(
        $wallet,
        LedgerTransactionType::TopUpCredit,
        Money::of($credit, Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    app(CaptureDepositObligation::class)->handle(test()->account);

    return $wallet->refresh();
}

describe('the terms a rule can set', function () {
    it('defaults to the reading that assumes the least', function () {
        /*
         * A deposit comes back unless somebody says otherwise, is not held past
         * cancellation unless somebody says so, and may be spent on services —
         * because locking money away is the stricter choice and nobody has
         * made it.
         */
        $rule = DepositRule::create([
            'scope' => RuleScope::Global,
            'effective_from' => CarbonImmutable::now(),
        ]);

        expect($rule->refundability)->toBe(DepositRefundability::Full)
            ->and($rule->reserved_until_cancellation)->toBeFalse()
            ->and($rule->deposit_usable_for_charges)->toBeTrue()
            ->and($rule->withdrawable_after_liabilities)->toBeTrue();
    });

    it('keeps the three questions apart', function () {
        // Folding them into one column would make two of these unsayable.
        refundabilityWallet([
            'refundability' => DepositRefundability::Full,
            'reserved_until_cancellation' => true,
            'deposit_usable_for_charges' => true,
        ]);

        $obligation = WalletDepositObligation::query()->firstOrFail();

        expect($obligation->refundability)->toBe(DepositRefundability::Full)
            ->and($obligation->reserved_until_cancellation)->toBeTrue()
            ->and($obligation->deposit_usable_for_charges)->toBeTrue();
    });

    it('carries a percentage only where one means something', function () {
        refundabilityWallet([
            'refundability' => DepositRefundability::Partial,
            'refundable_percent' => 50,
        ]);

        $obligation = WalletDepositObligation::query()->firstOrFail();

        expect($obligation->refundability->needsPercentage())->toBeTrue()
            ->and($obligation->refundable_percent)->toBe(50)
            ->and($obligation->refundability->refundsAnything())->toBeTrue();
    });

    it('says plainly when nothing comes back', function () {
        refundabilityWallet(['refundability' => DepositRefundability::None]);

        expect(WalletDepositObligation::query()->firstOrFail()->refundability->refundsAnything())
            ->toBeFalse();
    });
});

describe('what the terms change today', function () {
    it('lets a spendable deposit be spent', function () {
        $wallet = refundabilityWallet(['deposit_usable_for_charges' => true]);

        // 500,000 held, 300,000 of it deposit — all of it spendable.
        expect($wallet->usableBalance()->minorUnits)->toBe(500000)
            // But never withdrawable: that is the other question entirely.
            ->and($wallet->availableForWithdrawal()->minorUnits)->toBe(200000);
    });

    it('locks a deposit that may not cover charges', function () {
        $wallet = refundabilityWallet(['deposit_usable_for_charges' => false]);

        expect($wallet->deposit_usable_for_charges)->toBeFalse()
            ->and($wallet->usableBalance()->minorUnits)->toBe(200000)
            ->and($wallet->availableForWithdrawal()->minorUnits)->toBe(200000);
    });

    it('records a deposit held until cancellation', function () {
        $wallet = refundabilityWallet(['reserved_until_cancellation' => true]);

        expect($wallet->deposit_reserved_until_cancellation)->toBeTrue();
    });
});

it('holds a deposit to the terms it was taken under', function () {
    /*
     * The same reason the figures are captured. A deposit taken when it was
     * refundable does not become non-refundable because somebody edited the
     * policy afterwards — the refund module reads the obligation, not the rule.
     */
    refundabilityWallet(['refundability' => DepositRefundability::Full]);

    DepositRule::query()->update(['refundability' => DepositRefundability::None->value]);

    expect(WalletDepositObligation::query()->firstOrFail()->refundability)
        ->toBe(DepositRefundability::Full);
});
