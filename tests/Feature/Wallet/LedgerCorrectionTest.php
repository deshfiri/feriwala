<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Wallet\Actions\CorrectLedgerEntry;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * Corrections (P2-4, §23.2).
 *
 * §23.2 allows exactly three ways to put the ledger right — adjustment,
 * reversal, corrective — and none of them is editing what is already there. The
 * original stays and a new entry answers it, so the wrong figure and the
 * putting-right of it are both on the record.
 */

function correctionWallet(string $opening = '1000.00'): Wallet
{
    $wallet = app(OpenWallet::class)
        ->handle(testBusinessAccount(AccountStatus::Active));

    app(WalletService::class)->credit(
        $wallet,
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal($opening, Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    return $wallet->refresh();
}

it('answers a credit with the opposite movement', function () {
    $wallet = correctionWallet();
    $staff = User::factory()->staff()->create();

    $original = LedgerEntry::query()->latest('id')->firstOrFail();

    app(CorrectLedgerEntry::class)->reverse($original, $staff, 'Credited to the wrong account.');

    $reversal = LedgerEntry::query()->latest('id')->firstOrFail();

    expect($reversal->id)->not->toBe($original->id)
        ->and($reversal->debit->toDecimal())->toBe('1000.00')
        ->and($reversal->credit->toDecimal())->toBe('0.00')
        ->and($reversal->corrects_ledger_entry_id)->toBe($original->id)
        ->and($wallet->refresh()->total->toDecimal())->toBe('0.00');
});

it('leaves the original exactly as it was', function () {
    // The whole point. A ledger where the mistake disappears is a balance.
    $wallet = correctionWallet();
    $staff = User::factory()->staff()->create();

    $original = LedgerEntry::query()->latest('id')->firstOrFail();
    $before = $original->credit->toDecimal();

    app(CorrectLedgerEntry::class)->reverse($original, $staff, 'Wrong account.');

    expect($original->fresh()->credit->toDecimal())->toBe($before)
        ->and($original->fresh()->corrects_ledger_entry_id)->toBeNull()
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('links the reversal to what it reverses', function () {
    $staff = User::factory()->staff()->create();
    correctionWallet();

    $original = LedgerEntry::query()->latest('id')->firstOrFail();

    app(CorrectLedgerEntry::class)->reverse($original, $staff, 'Duplicate credit.');

    $reversal = LedgerEntry::query()->latest('id')->firstOrFail();

    expect($reversal->corrects->is($original))->toBeTrue();
});

it('reverses once however many times it is asked', function () {
    /*
     * Reversing twice would put the money back and take it away again, and
     * leave a ledger reading as if two different things had happened.
     */
    $wallet = correctionWallet();
    $staff = User::factory()->staff()->create();
    $original = LedgerEntry::query()->latest('id')->firstOrFail();

    $first = app(CorrectLedgerEntry::class)->reverse($original, $staff, 'Wrong account.');
    $second = app(CorrectLedgerEntry::class)->reverse($original->fresh(), $staff, 'Wrong account.');

    expect($second->id)->toBe($first->id)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and($wallet->refresh()->total->toDecimal())->toBe('0.00');
});

it('names the reversal §23.1 gives it where there is one', function () {
    $wallet = correctionWallet();
    $staff = User::factory()->staff()->create();

    app(WalletService::class)->credit(
        $wallet,
        LedgerTransactionType::CommissionCredit,
        Money::fromDecimal('50.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Commission'),
    );

    $commission = LedgerEntry::query()->latest('id')->firstOrFail();

    $reversal = app(CorrectLedgerEntry::class)
        ->reverse($commission, $staff, 'Order was returned.');

    expect($reversal->type)->toBe(LedgerTransactionType::CommissionReversal);
});

it('refuses a reversal with no reason', function () {
    correctionWallet();

    expect(fn () => app(CorrectLedgerEntry::class)->reverse(
        LedgerEntry::query()->latest('id')->firstOrFail(),
        User::factory()->staff()->create(),
        '',
    ))->toThrow(WalletOperationRefused::class);
});

describe('a manual adjustment', function () {
    it('moves money and records who and why', function () {
        $wallet = correctionWallet();
        $staff = User::factory()->staff()->create();

        $transaction = app(CorrectLedgerEntry::class)->adjust(
            $wallet,
            $staff,
            Money::fromDecimal('25.00', Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill after a courier failure.',
            'Approved verbally by finance.',
        );

        expect($wallet->refresh()->total->toDecimal())->toBe('1025.00')
            ->and($transaction->type)->toBe(LedgerTransactionType::ManualAdjustment)
            ->and($transaction->reason)->toBe('Goodwill after a courier failure.')
            ->and($transaction->created_by)->toBe($staff->id);
    });

    it('can take money back as well as give it', function () {
        // An adjustment that could only ever add would be no use for putting
        // right an overpayment.
        $wallet = correctionWallet();

        app(CorrectLedgerEntry::class)->adjust(
            $wallet,
            User::factory()->staff()->create(),
            Money::fromDecimal('25.00', Currency::BDT),
            LedgerDirection::Debit,
            'Overpaid last month.',
        );

        expect($wallet->refresh()->total->toDecimal())->toBe('975.00');
    });

    it('is written to the audit log as a sensitive act', function () {
        // Somebody moved money by hand. That is what a reconciliation goes
        // looking for first.
        $wallet = correctionWallet();
        $staff = User::factory()->staff()->create();

        app(CorrectLedgerEntry::class)->adjust(
            $wallet,
            $staff,
            Money::fromDecimal('25.00', Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill.',
        );

        $entry = AuditLog::query()->where('action', 'wallet.manual_adjustment')->firstOrFail();

        expect($entry->actor_id)->toBe($staff->id)
            ->and($entry->reason)->toBe('Goodwill.')
            ->and($entry->is_sensitive)->toBeTrue();
    });

    it('keeps its staff note off the account holder\'s record', function () {
        $wallet = correctionWallet();

        $transaction = app(CorrectLedgerEntry::class)->adjust(
            $wallet,
            User::factory()->staff()->create(),
            Money::fromDecimal('25.00', Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill.',
            'Customer complained loudly on social media.',
        );

        expect(json_encode($transaction))->not->toContain('social media');
    });
});
