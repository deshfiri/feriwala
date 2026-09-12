<?php

use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\DepositGuard;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;

/*
 * Turning a policy into a promise (P2-13, P2-14, §24.1, §24.2).
 *
 * A deposit rule is what the platform requires; an obligation is what one
 * account is held to. They have to be separate, because a rule raised in June
 * must not change what an account was restricted for in March.
 */

function obligationRule(array $attributes = []): DepositRule
{
    return DepositRule::create([
        'scope' => RuleScope::Global,
        'required_initial_deposit_minor' => 300000,
        'minimum_balance_minor' => 100000,
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
        ...$attributes,
    ]);
}

function obligationWallet(int $credit = 0): Wallet
{
    $wallet = app(OpenWallet::class)->handle(test()->account);

    if ($credit > 0) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of($credit, Currency::BDT),
            new PostingContext(source: 'test', description: 'Opening'),
        );
    }

    return $wallet->refresh();
}

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
});

describe('capturing', function () {
    it('copies the rule onto the wallet', function () {
        $rule = obligationRule();
        $wallet = obligationWallet();

        app(CaptureDepositObligation::class)->handle($this->account);

        $wallet->refresh();

        expect($wallet->required_deposit_minor->minorUnits)->toBe(300000)
            ->and($wallet->minimum_balance_minor->minorUnits)->toBe(100000)
            ->and($wallet->deposit_rule_id)->toBe($rule->id)
            ->and($wallet->obligation_captured_at)->not->toBeNull();
    });

    it('keeps the capture as its own record', function () {
        obligationRule();
        obligationWallet();

        app(CaptureDepositObligation::class)->handle($this->account, CaptureDepositObligation::ACTIVATION);

        $obligation = WalletDepositObligation::query()->firstOrFail();

        expect($obligation->business_account_id)->toBe($this->account->id)
            ->and($obligation->required_deposit_minor->minorUnits)->toBe(300000)
            ->and($obligation->minimum_balance_minor->minorUnits)->toBe(100000)
            ->and($obligation->source)->toBe(CaptureDepositObligation::ACTIVATION);
    });

    it('stops a later rule change from rewriting what was agreed', function () {
        /*
         * The whole reason capture exists. The rule moves; the promise does not.
         */
        $rule = obligationRule();
        $wallet = obligationWallet();

        app(CaptureDepositObligation::class)->handle($this->account);

        $rule->forceFill(['minimum_balance_minor' => Money::of(900000, Currency::BDT)])->save();

        expect($wallet->refresh()->minimum_balance_minor->minorUnits)->toBe(100000);
    });

    it('records that nothing is required when no rule applies', function () {
        // "Nothing is required of this account" is the answer to why nothing
        // was enforced, and it is worth writing down.
        $wallet = obligationWallet();

        $obligation = app(CaptureDepositObligation::class)->handle($this->account);

        expect($obligation)->not->toBeNull()
            ->and($obligation->deposit_rule_id)->toBeNull()
            ->and($obligation->requiresAnything())->toBeFalse()
            ->and($wallet->refresh()->required_deposit_minor->minorUnits)->toBe(0);
    });

    it('captures once when the figures have not changed', function () {
        /*
         * Re-running enforcement must not create a second obligation — or, more
         * to the point, move a deadline somebody is counting down.
         */
        obligationRule(['deposit_deadline_days' => 14]);
        obligationWallet();

        app(CaptureDepositObligation::class)->handle($this->account);
        $due = Wallet::query()->firstOrFail()->deposit_due_at;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(3));

        app(CaptureDepositObligation::class)->handle($this->account);
        app(CaptureDepositObligation::class)->handle($this->account);

        expect(WalletDepositObligation::query()->count())->toBe(1)
            ->and(Wallet::query()->firstOrFail()->deposit_due_at->toIso8601String())
            ->toBe($due->toIso8601String());

        CarbonImmutable::setTestNow();
    });

    it('captures again when the requirement actually changes', function () {
        obligationRule();
        obligationWallet();

        app(CaptureDepositObligation::class)->handle($this->account);

        DepositRule::query()->update(['effective_until' => CarbonImmutable::now()]);
        obligationRule([
            'minimum_balance_minor' => 500000,
            'effective_from' => CarbonImmutable::now(),
        ]);

        app(CaptureDepositObligation::class)->handle($this->account);

        expect(WalletDepositObligation::query()->count())->toBe(2)
            ->and(Wallet::query()->firstOrFail()->minimum_balance_minor->minorUnits)->toBe(500000);
    });

    it('sets the deadline from the rule rather than from nothing', function () {
        obligationRule(['deposit_deadline_days' => 30]);
        obligationWallet();

        app(CaptureDepositObligation::class)->handle($this->account);

        expect(Wallet::query()->firstOrFail()->deposit_due_at?->toDateString())
            ->toBe(CarbonImmutable::now()->addDays(30)->toDateString());
    });

    it('is taken on at activation', function () {
        // §24 is about what an account must hold to trade, so the moment it
        // starts trading is the moment it takes the obligation on.
        obligationRule();

        $pending = testAccountReadyForActivation();

        app(ActivateAccount::class)->handle($pending, approvedBy: $pending->owner_id);

        $wallet = Wallet::query()->where('business_account_id', $pending->id)->firstOrFail();

        expect($wallet->required_deposit_minor->minorUnits)->toBe(300000)
            ->and($wallet->minimum_balance_minor->minorUnits)->toBe(100000)
            ->and(WalletDepositObligation::query()
                ->where('business_account_id', $pending->id)
                ->where('source', CaptureDepositObligation::ACTIVATION)
                ->exists())->toBeTrue();
    });
});

describe('the balances it changes (§24.2)', function () {
    it('keeps the minimum balance out of what can be spent', function () {
        // §24.2 lists the reserved minimum balance separately from the deposit
        // because they are different promises. This one has to stay.
        obligationRule(['required_initial_deposit_minor' => 0, 'minimum_balance_minor' => 100000]);
        $wallet = obligationWallet(500000);

        app(CaptureDepositObligation::class)->handle($this->account);

        expect($wallet->refresh()->usableBalance()->minorUnits)->toBe(400000)
            ->and($wallet->availableForWithdrawal()->minorUnits)->toBe(400000);
    });

    it('keeps it out of what can be withdrawn as well', function () {
        obligationRule();
        $wallet = obligationWallet(500000);

        app(CaptureDepositObligation::class)->handle($this->account);

        // 500,000 less the 100,000 minimum and the 300,000 deposit.
        expect($wallet->refresh()->availableForWithdrawal()->minorUnits)->toBe(100000);
    });

    it('lets a deposit be spent on services when the rule allows it', function () {
        /*
         * §24.4's "usable for service charges". The deposit still cannot leave
         * as a withdrawal — that is the difference between the two questions.
         */
        obligationRule();
        $wallet = obligationWallet(500000);

        app(CaptureDepositObligation::class)->handle($this->account);

        $wallet->refresh();

        expect($wallet->deposit_usable_for_charges)->toBeTrue()
            ->and($wallet->usableBalance()->minorUnits)->toBe(400000)
            ->and($wallet->availableForWithdrawal()->minorUnits)->toBe(100000);
    });

    it('locks the deposit out of spending when the rule does not', function () {
        obligationRule();
        $wallet = obligationWallet(500000);

        app(CaptureDepositObligation::class)->handle($this->account);

        $wallet->forceFill(['deposit_usable_for_charges' => false])->save();

        expect($wallet->refresh()->usableBalance()->minorUnits)->toBe(100000);
    });

    it('never reports a withdrawable balance above the spendable one', function () {
        obligationRule();
        $wallet = obligationWallet(350000);

        app(CaptureDepositObligation::class)->handle($this->account);

        $wallet->refresh();

        expect($wallet->availableForWithdrawal()->minorUnits)
            ->toBeLessThanOrEqual($wallet->usableBalance()->minorUnits)
            ->and($wallet->availableForWithdrawal()->minorUnits)->toBe(0);
    });

    it('refuses to spend money the account is required to keep', function () {
        // The invariant, not just the display: the posting service reads the
        // same spendable balance.
        obligationRule(['required_initial_deposit_minor' => 0, 'minimum_balance_minor' => 400000]);
        $wallet = obligationWallet(500000);

        app(CaptureDepositObligation::class)->handle($this->account);

        expect(fn () => app(WalletService::class)->debit(
            $wallet->refresh(),
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(200000, Currency::BDT),
            new PostingContext(source: 'test', description: 'A charge'),
        ))->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->total_minor->minorUnits)->toBe(500000);
    });

    it('reports the shortfall against both obligations together', function () {
        obligationRule();
        $wallet = obligationWallet(250000);

        app(CaptureDepositObligation::class)->handle($this->account);

        $wallet->refresh();

        // 400,000 required in total, 250,000 held.
        expect($wallet->reservedObligation()->minorUnits)->toBe(400000)
            ->and($wallet->obligationShortfall()->minorUnits)->toBe(150000)
            ->and($wallet->meetsObligation())->toBeFalse()
            // The deposit alone is not met either, and says so separately.
            ->and($wallet->meetsRequiredDeposit())->toBeFalse()
            ->and($wallet->shortfall()->minorUnits)->toBe(50000);
    });
});

describe('the guard a service setup knocks on (§24)', function () {
    it('permits a setup when the obligation is met', function () {
        obligationRule();
        $wallet = obligationWallet(500000);

        app(CaptureDepositObligation::class)->handle($this->account);

        expect(app(DepositGuard::class)->permits($wallet->refresh()))->toBeTrue();
    });

    it('refuses one when the account is short', function () {
        obligationRule();
        $wallet = obligationWallet(100000);

        app(CaptureDepositObligation::class)->handle($this->account);

        expect(app(DepositGuard::class)->permits($wallet->refresh()))->toBeFalse()
            ->and(fn () => app(DepositGuard::class)->assert($wallet->refresh()))
            ->toThrow(WalletOperationRefused::class);
    });

    it('counts what the service itself will cost', function () {
        /*
         * Meeting the minimum and then immediately falling below it is not
         * meeting it: the service would be granted and the account restricted
         * in the same breath.
         */
        obligationRule(['required_initial_deposit_minor' => 0, 'minimum_balance_minor' => 100000]);
        $wallet = obligationWallet(150000);

        app(CaptureDepositObligation::class)->handle($this->account);

        $guard = app(DepositGuard::class);
        $charge = Money::of(80000, Currency::BDT);

        expect($guard->permits($wallet->refresh(), $charge))->toBeFalse()
            ->and($guard->shortfallFor($wallet->refresh(), $charge)->minorUnits)->toBe(30000);
    });

    it('says a wallet with no obligation is free to proceed', function () {
        $wallet = obligationWallet(1000);

        expect(app(DepositGuard::class)->permits($wallet))->toBeTrue()
            ->and(app(DepositGuard::class)->shortfallFor($wallet)->minorUnits)->toBe(0);
    });
});
