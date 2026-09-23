<?php

use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Models\Wallet;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * The wallet itself (P2-1, §23, §24.2).
 *
 * §23 opens with "Every Active Account will have a Wallet and Financial Ledger",
 * so the wallet belongs to the business rather than the person, opens with the
 * activation, and opens empty.
 */

it('belongs to the business account, not the person', function () {
    // D1 and D23: everything commercial hangs off the business.
    $account = testBusinessAccount(AccountStatus::Active);

    $wallet = app(OpenWallet::class)->handle($account);

    expect($wallet->business_account_id)->toBe($account->id)
        ->and($wallet->businessAccount->is($account))->toBeTrue();
});

it('opens empty', function () {
    /*
     * No opening balance and no opening entry. Every figure in a wallet has to
     * be explained by a ledger entry, and a wallet that started at some number
     * would have one that is not.
     */
    $wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));

    expect($wallet->total->toDecimal())->toBe('0.00')
        ->and($wallet->reserved->toDecimal())->toBe('0.00')
        ->and($wallet->pending->toDecimal())->toBe('0.00')
        ->and($wallet->hold->toDecimal())->toBe('0.00')
        ->and($wallet->required_deposit->toDecimal())->toBe('0.00')
        ->and($wallet->cod_receivable->toDecimal())->toBe('0.00')
        ->and($wallet->usableBalance()->toDecimal())->toBe('0.00')
        ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('0.00')
        ->and($wallet->currency_code)->toBe('BDT');
});

it('opens exactly one however many times it is asked for', function () {
    // Two requests at once, or an account activated twice, must not split its
    // money across two wallets.
    $account = testBusinessAccount(AccountStatus::Active);
    $open = app(OpenWallet::class);

    $first = $open->handle($account);
    $second = $open->handle($account);

    expect($second->id)->toBe($first->id)
        ->and(Wallet::query()->count())->toBe(1);
});

describe('the derived balances', function () {
    /**
     * A wallet holding `$total` Taka with the given claims against it.
     *
     * @param  array<string, string>  $claims  column => decimal Taka
     */
    function walletHolding(string $total, array $claims = []): Wallet
    {
        $wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));

        $currency = $wallet->currency();

        // Written directly because this is a test of the arithmetic, not of the
        // posting service — which has its own tests, and is the only thing
        // allowed to do this for real.
        $wallet->forceFill(array_merge(
            ['total' => Money::fromDecimal($total, $currency)],
            array_map(fn (string $value) => Money::fromDecimal($value, $currency), $claims),
        ))->save();

        return $wallet->refresh();
    }

    it('is what is there less everything that is not spendable', function () {
        // §24.2: usable service balance. Reserved, held and pending money is
        // present but not available to spend.
        $wallet = walletHolding('1000.00', [
            'reserved' => '200.00',
            'hold' => '100.00',
            'pending' => '50.00',
        ]);

        expect($wallet->usableBalance()->toDecimal())->toBe('650.00');
    });

    it('keeps the required deposit out of what can be withdrawn', function () {
        /*
         * A withdrawal that emptied the required deposit would leave the account
         * unable to trade the moment it succeeded. It stays spendable on
         * services (§24.4) and unavailable to take out.
         */
        $wallet = walletHolding('1000.00', ['required_deposit' => '400.00']);

        expect($wallet->usableBalance()->toDecimal())->toBe('1000.00')
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('600.00');
    });

    it('never reports a negative spending power', function () {
        // A hold on the whole balance is legitimate. "You have minus two
        // hundred taka to spend" is not a true statement about spending.
        $wallet = walletHolding('100.00', ['hold' => '250.00']);

        expect($wallet->usableBalance()->toDecimal())->toBe('0.00')
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('0.00');
    });

    it('says what is missing rather than showing a negative', function () {
        $wallet = walletHolding('300.00', ['required_deposit' => '500.00']);

        expect($wallet->meetsRequiredDeposit())->toBeFalse()
            ->and($wallet->shortfall()->toDecimal())->toBe('200.00');
    });

    it('reports COD receivable beside the balance and never inside it', function () {
        // Money a courier is holding is owed to the account and is not in the
        // wallet; counting it as balance would let it be spent twice.
        $wallet = walletHolding('500.00', ['cod_receivable' => '900.00']);

        expect($wallet->total->toDecimal())->toBe('500.00')
            ->and($wallet->usableBalance()->toDecimal())->toBe('500.00')
            ->and($wallet->cod_receivable->toDecimal())->toBe('900.00');
    });

    it('describes the same money one way for every screen', function () {
        $balances = walletHolding('1000.00', ['reserved' => '250.00'])->toBalances();

        expect($balances['currency'])->toBe('BDT')
            ->and($balances['total']['amount'])->toBe('1000.00')
            ->and($balances['usable']['amount'])->toBe('750.00')
            ->and($balances['reserved']['amount'])->toBe('250.00')
            ->and($balances)->toHaveKeys([
                'available_for_withdrawal', 'required_deposit', 'pending',
                'hold', 'cod_receivable', 'meets_required_deposit', 'shortfall',
            ]);
    });
});

it('is opened by activation itself', function () {
    /*
     * §23 makes the wallet a property of being active, so it arrives with the
     * activation rather than on first use — an active account with no wallet is
     * a state nothing else should have to handle.
     */
    $account = testAccountReadyForActivation();

    // Who approved is not what this is about — the action takes an id, and the
    // permission that guards it is the controller's business.
    $approver = User::factory()->staff()->create();

    app(ActivateAccount::class)->handle($account, $approver->id);

    $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

    expect($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($wallet)->not->toBeNull()
        ->and($wallet->total->equals(Money::zero(Currency::BDT)))->toBeTrue();
});
