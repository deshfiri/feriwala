<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\EvaluateWalletBalance;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletBalanceState;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;

/*
 * Thresholds and the clock they start (P2-15, §24.1, §24.3).
 *
 * §24.1 configures a low threshold, a critical one and a grace period; §24.3
 * acts when they are crossed. Between them sits a fact nobody else records: when
 * the balance first fell short — and the deadline that came with it, which must
 * not move because somebody edited a setting afterwards.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
});

function thresholdWallet(int $credit, array $rule = []): Wallet
{
    DepositRule::create([
        'scope' => RuleScope::Global,
        'required_initial_deposit_minor' => 0,
        'minimum_balance_minor' => 200000,
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
        ...$rule,
    ]);

    $wallet = app(OpenWallet::class)->handle(test()->account);

    if ($credit > 0) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of($credit, Currency::BDT),
            new PostingContext(source: 'test', description: 'Opening'),
        );
    }

    app(CaptureDepositObligation::class)->handle(test()->account);

    return $wallet->refresh();
}

describe('reading the state', function () {
    it('calls a wallet healthy when it holds what it must', function () {
        $wallet = thresholdWallet(500000);

        expect(app(EvaluateWalletBalance::class)->handle($wallet))
            ->toBe(WalletBalanceState::Healthy);
    });

    it('calls it low when it passes the threshold but still meets the obligation', function () {
        /*
         * "Low" does not mean nearly out of money. It means the balance passed a
         * figure somebody chose as worth mentioning — which is well above the
         * requirement here, and nothing is restricted for it.
         */
        $wallet = thresholdWallet(300000, ['low_balance_threshold_minor' => 400000]);

        $state = app(EvaluateWalletBalance::class)->handle($wallet);

        expect($state)->toBe(WalletBalanceState::Low)
            ->and($state->isShort())->toBeFalse()
            ->and($wallet->refresh()->shortfall_since)->toBeNull();
    });

    it('calls it critical when it is below what it is required to hold', function () {
        $wallet = thresholdWallet(100000);

        $state = app(EvaluateWalletBalance::class)->handle($wallet);

        expect($state)->toBe(WalletBalanceState::Critical)
            ->and($state->isShort())->toBeTrue();
    });

    it('records the state on the wallet so a list need not re-derive it', function () {
        $wallet = thresholdWallet(100000);

        app(EvaluateWalletBalance::class)->handle($wallet);

        expect($wallet->refresh()->balance_state)->toBe(WalletBalanceState::Critical)
            ->and($wallet->balance_checked_at)->not->toBeNull();
    });

    it('answers without writing anything when only asked', function () {
        // A screen should be able to ask where a wallet stands without the act
        // of looking starting a grace period.
        $wallet = thresholdWallet(100000);

        expect(app(EvaluateWalletBalance::class)->stateOf($wallet))
            ->toBe(WalletBalanceState::Critical)
            ->and($wallet->refresh()->shortfall_since)->toBeNull();
    });
});

describe('the grace period', function () {
    it('stamps a deadline when the shortfall begins', function () {
        $wallet = thresholdWallet(100000, ['grace_period_days' => 14]);

        app(EvaluateWalletBalance::class)->handle($wallet);

        $wallet->refresh();

        expect($wallet->shortfall_since?->toDateString())
            ->toBe(CarbonImmutable::now()->toDateString())
            ->and($wallet->grace_ends_at?->toDateString())
            ->toBe(CarbonImmutable::now()->addDays(14)->toDateString());
    });

    it('does not move the deadline on a later sweep', function () {
        /*
         * The promise. "You have fourteen days" recalculated on every sweep is a
         * deadline that never arrives — or one that moves when an administrator
         * edits a setting, which is the complaint afterwards.
         */
        $wallet = thresholdWallet(100000, ['grace_period_days' => 14]);

        app(EvaluateWalletBalance::class)->handle($wallet);
        $deadline = $wallet->refresh()->grace_ends_at;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(5));
        app(EvaluateWalletBalance::class)->handle($wallet->refresh());

        expect($wallet->refresh()->grace_ends_at->toIso8601String())
            ->toBe($deadline->toIso8601String());

        CarbonImmutable::setTestNow();
    });

    it('does not move it when the rule changes underneath', function () {
        // The obligation was captured with fourteen days. Changing the policy
        // to thirty does not hand this account another sixteen.
        $wallet = thresholdWallet(100000, ['grace_period_days' => 14]);

        app(EvaluateWalletBalance::class)->handle($wallet);
        $deadline = $wallet->refresh()->grace_ends_at;

        DepositRule::query()->update(['grace_period_days' => 30]);

        app(EvaluateWalletBalance::class)->handle($wallet->refresh());

        expect($wallet->refresh()->grace_ends_at->toIso8601String())
            ->toBe($deadline->toIso8601String());
    });

    it('clears the clock once the account is back above the line', function () {
        // Not paused — gone. A shortfall next month is a new shortfall with its
        // own grace period.
        $wallet = thresholdWallet(100000, ['grace_period_days' => 14]);

        app(EvaluateWalletBalance::class)->handle($wallet);

        app(WalletService::class)->credit(
            $wallet->refresh(),
            LedgerTransactionType::TopUpCredit,
            Money::of(200000, Currency::BDT),
            new PostingContext(source: 'test', description: 'Top-up'),
        );

        app(EvaluateWalletBalance::class)->handle($wallet->refresh());

        expect($wallet->refresh()->shortfall_since)->toBeNull()
            ->and($wallet->grace_ends_at)->toBeNull()
            ->and($wallet->balance_state)->toBe(WalletBalanceState::Healthy);
    });

    it('treats a wallet given no grace as out of time immediately', function () {
        // §24.1 makes the period optional. An account that was never given one
        // is short from the moment it falls short.
        $wallet = thresholdWallet(100000);

        app(EvaluateWalletBalance::class)->handle($wallet);

        expect($wallet->refresh()->grace_ends_at)->toBeNull()
            ->and(app(EvaluateWalletBalance::class)->graceHasExpired($wallet))->toBeTrue();
    });

    it('says the grace has not expired while it is still running', function () {
        $wallet = thresholdWallet(100000, ['grace_period_days' => 14]);

        app(EvaluateWalletBalance::class)->handle($wallet);

        expect(app(EvaluateWalletBalance::class)->graceHasExpired($wallet->refresh()))
            ->toBeFalse();
    });

    it('says it has once the day arrives', function () {
        $wallet = thresholdWallet(100000, ['grace_period_days' => 14]);

        app(EvaluateWalletBalance::class)->handle($wallet);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(15));

        expect(app(EvaluateWalletBalance::class)->graceHasExpired($wallet->refresh()))
            ->toBeTrue();

        CarbonImmutable::setTestNow();
    });

    it('never says a healthy wallet is out of time', function () {
        $wallet = thresholdWallet(500000);

        app(EvaluateWalletBalance::class)->handle($wallet);

        expect(app(EvaluateWalletBalance::class)->graceHasExpired($wallet->refresh()))
            ->toBeFalse();
    });
});
