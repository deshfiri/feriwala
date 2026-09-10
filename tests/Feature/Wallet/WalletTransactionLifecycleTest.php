<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * The wallet transaction lifecycle (P2-7, §23.3).
 *
 * §23.3 gives a transaction twelve statuses to move through and §23.2 makes a
 * ledger entry immutable. Both cannot be true of one row, so this is the half
 * that changes over time and the ledger is the half that never does.
 */

function lifecycleWallet(): Wallet
{
    return app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function lifecycleTransaction(array $overrides = []): WalletTransaction
{
    $wallet = $overrides['wallet'] ?? lifecycleWallet();
    unset($overrides['wallet']);

    return WalletTransaction::create(array_merge([
        'wallet_id' => $wallet->id,
        'business_account_id' => $wallet->business_account_id,
        'type' => LedgerTransactionType::TopUpCredit,
        'direction' => LedgerDirection::Credit,
        'source' => 'test',
        'amount_minor' => 50000,
        'currency_code' => 'BDT',
        'status' => WalletTransactionStatus::Initiated,
        'description' => 'Wallet top-up',
    ], $overrides));
}

it('starts where §23.3 starts', function () {
    $transaction = lifecycleTransaction();

    expect($transaction->status)->toBe(WalletTransactionStatus::Initiated)
        ->and($transaction->reference)->toStartWith('TXN')
        ->and($transaction->isRealised())->toBeFalse()
        ->and($transaction->isCredit())->toBeTrue();
});

it('walks the ordinary route from initiated to settled', function () {
    $transaction = lifecycleTransaction();

    $transaction->transitionTo(WalletTransactionStatus::Pending);
    $transaction->transitionTo(WalletTransactionStatus::Available);
    $transaction->transitionTo(WalletTransactionStatus::Settled);
    $transaction->save();

    expect($transaction->fresh()->status)->toBe(WalletTransactionStatus::Settled)
        ->and($transaction->isRealised())->toBeTrue();
});

it('lets a review end either way and never on its own', function () {
    // A hold or a review is lifted one way or the other; neither is an ending.
    $held = lifecycleTransaction(['status' => WalletTransactionStatus::UnderReview]);

    expect($held->canTransitionTo(WalletTransactionStatus::Approved))->toBeTrue()
        ->and($held->canTransitionTo(WalletTransactionStatus::Rejected))->toBeTrue()
        ->and(WalletTransactionStatus::UnderReview->isTerminal())->toBeFalse();
});

it('refuses to revive a failed transaction', function () {
    /*
     * Retried as a new transaction, not revived — the same rule payments
     * follow. Reviving one would give two transactions the same identity and
     * one of them the wrong history.
     */
    $failed = lifecycleTransaction(['status' => WalletTransactionStatus::Failed]);

    expect(fn () => $failed->transitionTo(WalletTransactionStatus::Available))
        ->toThrow(IllegalStateTransition::class);
});

it('refuses to un-pay money', function () {
    // Money that went out can be reversed. It cannot be made not to have gone.
    $paid = lifecycleTransaction(['status' => WalletTransactionStatus::Paid]);

    expect(fn () => $paid->transitionTo(WalletTransactionStatus::Pending))
        ->toThrow(IllegalStateTransition::class)
        ->and($paid->canTransitionTo(WalletTransactionStatus::Reversed))->toBeTrue();
});

it('will not let two commands share an idempotency key', function () {
    /*
     * The key belongs to the command — "credit this wallet for that payment" —
     * and refusing the second attempt at the envelope is what stops a duplicate
     * ever reaching the posting.
     */
    $wallet = lifecycleWallet();

    lifecycleTransaction(['wallet' => $wallet, 'idempotency_key' => 'topup:PAY-1']);

    expect(fn () => lifecycleTransaction([
        'wallet' => $wallet,
        'idempotency_key' => 'topup:PAY-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps its internal note away from a serialised payload', function () {
    $transaction = lifecycleTransaction(['internal_note' => 'Flagged by finance.']);

    expect($transaction->toArray())->not->toHaveKey('internal_note')
        ->and(json_encode($transaction))->not->toContain('Flagged by finance');
});
