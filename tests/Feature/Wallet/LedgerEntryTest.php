<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The financial ledger (P2-2, P2-3, §23.2).
 *
 * "Every financial transaction must create an immutable Ledger Entry." Written
 * once, never changed, never deleted — the same rule the audit log follows, and
 * for the same reason: a record that can be edited afterwards is not evidence of
 * anything.
 */

function ledgerTestWallet(): Wallet
{
    return app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));
}

/**
 * A posted entry. Written directly because these tests are about the row and
 * its protections; the posting service that writes them for real is P2-5.
 *
 * @param  array<string, mixed>  $overrides
 */
function ledgerTestEntry(Wallet $wallet, array $overrides = []): LedgerEntry
{
    return LedgerEntry::create(array_merge([
        'wallet_id' => $wallet->id,
        'business_account_id' => $wallet->business_account_id,
        'type' => LedgerTransactionType::TopUpCredit,
        'source' => 'test',
        'credit_minor' => 50000,
        'debit_minor' => 0,
        'currency_code' => 'BDT',
        'balance_before_minor' => 0,
        'balance_after_minor' => 50000,
        'available_minor' => 50000,
        'status' => WalletTransactionStatus::Settled,
        'description' => 'Top-up',
        'created_at' => now(),
    ], $overrides));
}

describe('what an entry records', function () {
    it('carries the full §23.2 column set', function () {
        $wallet = ledgerTestWallet();

        $entry = ledgerTestEntry($wallet, [
            'internal_note' => 'Checked against the gateway statement.',
            'description' => 'Wallet top-up',
        ]);

        expect($entry->reference)->toStartWith('TXN')
            ->and($entry->public_id)->not->toBeEmpty()
            ->and($entry->type)->toBe(LedgerTransactionType::TopUpCredit)
            ->and($entry->status)->toBe(WalletTransactionStatus::Settled)
            ->and($entry->balance_before_minor->minorUnits)->toBe(0)
            ->and($entry->balance_after_minor->minorUnits)->toBe(50000)
            ->and($entry->description)->toBe('Wallet top-up')
            ->and($entry->internal_note)->toBe('Checked against the gateway statement.');
    });

    it('gives every entry a reference of its own', function () {
        $wallet = ledgerTestWallet();

        $references = collect(range(1, 5))
            ->map(fn () => ledgerTestEntry($wallet)->reference);

        expect($references->unique())->toHaveCount(5);
    });

    it('stores both sides positive and tells them apart by column', function () {
        /*
         * Summing "everything debited in March" then needs no sign handling,
         * and a misplaced minus cannot quietly become a credit.
         */
        $wallet = ledgerTestWallet();

        $credit = ledgerTestEntry($wallet);
        $debit = ledgerTestEntry($wallet, [
            'type' => LedgerTransactionType::PackageFeeDebit,
            'credit_minor' => 0,
            'debit_minor' => 20000,
            'balance_before_minor' => 50000,
            'balance_after_minor' => 30000,
        ]);

        expect($credit->isCredit())->toBeTrue()
            ->and($credit->signedAmount()->minorUnits)->toBe(50000)
            ->and($debit->isCredit())->toBeFalse()
            ->and($debit->debit_minor->minorUnits)->toBe(20000)
            ->and($debit->signedAmount()->minorUnits)->toBe(-20000)
            ->and($debit->amount()->minorUnits)->toBe(20000);
    });

    it('knows whether its own arithmetic holds', function () {
        // Read by the integrity sweep: an entry whose ends do not match its own
        // movement is corrupt whatever the wallet says.
        $wallet = ledgerTestWallet();

        expect(ledgerTestEntry($wallet)->balances())->toBeTrue()
            ->and(ledgerTestEntry($wallet, ['balance_after_minor' => 999])->balances())
            ->toBeFalse();
    });

    it('never serialises the internal note', function () {
        // §23.2's internal note is for staff. A statement that leaked one would
        // be handing a reviewer's private words to the person they are about.
        $entry = ledgerTestEntry(ledgerTestWallet(), ['internal_note' => 'Suspected chargeback.']);

        expect($entry->toArray())->not->toHaveKey('internal_note')
            ->and(json_encode($entry))->not->toContain('Suspected chargeback');
    });
});

describe('immutability', function () {
    it('refuses to be edited through the model', function () {
        $entry = ledgerTestEntry(ledgerTestWallet());

        expect(fn () => $entry->forceFill(['credit_minor' => 1])->save())
            ->toThrow(RuntimeException::class);
    });

    it('refuses to be deleted through the model', function () {
        $entry = ledgerTestEntry(ledgerTestWallet());

        expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
    });

    /**
     * Attempt an illegal write and come back able to ask questions.
     *
     * PostgreSQL aborts the whole transaction when a statement fails, which is
     * exactly right for a financial guard — a unit of work containing an
     * illegal ledger write should not half-succeed. Here it means the attempt
     * needs a savepoint of its own, or there is no session left to assert in.
     */
    function ledgerTestRefuses(Closure $write): void
    {
        expect(fn () => DB::transaction($write))->toThrow(QueryException::class);
    }

    it('refuses an update that goes around the model entirely', function () {
        /*
         * The guard that matters. The query builder fires no model events, so
         * a raw update walks straight past the model — and so does tinker, a
         * seeder, a migration, and anybody with a psql prompt. §23.2 has to
         * hold for all of them, so it is stated in the one language they all
         * speak.
         */
        $entry = ledgerTestEntry(ledgerTestWallet());

        ledgerTestRefuses(fn () => DB::table('ledger_entries')
            ->where('id', $entry->id)
            ->update(['credit_minor' => 999999]));

        expect(LedgerEntry::query()->findOrFail($entry->id)->credit_minor->minorUnits)
            ->toBe(50000);
    });

    it('refuses a delete that goes around the model entirely', function () {
        $entry = ledgerTestEntry(ledgerTestWallet());

        ledgerTestRefuses(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete());

        expect(LedgerEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
    });

    it('refuses a delete of the whole ledger', function () {
        // The row-level trigger catches a `delete from ledger_entries` with no
        // where clause too, which is the shape a mistake usually takes.
        ledgerTestEntry(ledgerTestWallet());

        ledgerTestRefuses(fn () => DB::table('ledger_entries')->delete());

        expect(LedgerEntry::query()->count())->toBe(1);
    });
});

describe('the transaction types', function () {
    it('has all twenty-six §23.1 names', function () {
        // Listed in full including the ones whose module is not built yet: a
        // ledger migrated every time a module lands is a ledger whose history
        // gets renumbered.
        expect(LedgerTransactionType::cases())->toHaveCount(26);
    });

    it('gives every type a label somebody could read', function () {
        foreach (LedgerTransactionType::cases() as $type) {
            expect($type->label())->not->toBeEmpty()
                ->and($type->label())->not->toContain('_');
        }
    });
});

describe('the transaction statuses', function () {
    it('has all twelve §23.3 statuses', function () {
        expect(WalletTransactionStatus::cases())->toHaveCount(12);
    });

    it('knows which of them mean the money is really there', function () {
        expect(WalletTransactionStatus::Available->isRealised())->toBeTrue()
            ->and(WalletTransactionStatus::Settled->isRealised())->toBeTrue()
            ->and(WalletTransactionStatus::Paid->isRealised())->toBeTrue()
            ->and(WalletTransactionStatus::Pending->isRealised())->toBeFalse()
            ->and(WalletTransactionStatus::OnHold->isRealised())->toBeFalse()
            ->and(WalletTransactionStatus::Approved->isRealised())->toBeFalse();
    });

    it('never lets a failed transaction come back', function () {
        // Retried as a new transaction, not revived — the same rule payments
        // follow, and for the same reason.
        expect(WalletTransactionStatus::Failed->transitionsTo())->toBe([])
            ->and(WalletTransactionStatus::Rejected->transitionsTo())->toBe([])
            ->and(WalletTransactionStatus::Cancelled->transitionsTo())->toBe([])
            ->and(WalletTransactionStatus::Reversed->transitionsTo())->toBe([]);
    });

    it('lets paid money be reversed and never un-paid', function () {
        expect(WalletTransactionStatus::Paid->transitionsTo())
            ->toBe([WalletTransactionStatus::Reversed]);
    });

    it('carries a label beside every tone', function () {
        // §33.9: a financial status must never depend on colour alone.
        foreach (WalletTransactionStatus::cases() as $status) {
            expect($status->label())->not->toBeEmpty()
                ->and($status->tone())->toBeIn(['success', 'danger', 'warning', 'info', 'neutral']);
        }
    });
});
