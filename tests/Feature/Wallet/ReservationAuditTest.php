<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Models\WalletTransactionEvent;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Where the boundary between the two records sits (§23.1, §23.2, §23.3).
 *
 * §23.1 names no "reserve" and no "hold" — every transaction type it lists is a
 * credit or a debit. §23.3 makes *Pending* and *On Hold* statuses a transaction
 * passes through. So a reservation is not a movement of value: the money is
 * still in the wallet, merely spoken for, and no ledger entry is written for it.
 *
 * That is a deliberate line, and this file is what stops it drifting in either
 * direction — into a ledger full of entries that moved nothing, or into a claim
 * that leaves no trace of having existed.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->wallet = app(OpenWallet::class)->handle($this->account);

    app(WalletService::class)->credit(
        $this->wallet,
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal('1000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    $this->wallet->refresh();
});

function auditReserve(string $amount = '300.00', ?User $actor = null): WalletTransaction
{
    return app(WalletService::class)->reserve(
        test()->wallet->refresh(),
        LedgerTransactionType::ServiceFeeDebit,
        Money::fromDecimal($amount, Currency::BDT),
        new PostingContext(
            source: 'test',
            description: 'Reserved against a charge',
            idempotencyKey: 'audit:'.$amount,
            reason: 'A charge is coming.',
            actorId: $actor?->id,
        ),
    );
}

function auditEvents(WalletTransaction $transaction): Collection
{
    return WalletTransactionEvent::query()
        ->where('wallet_transaction_id', $transaction->id)
        ->orderBy('id')
        ->get();
}

describe('the boundary', function () {
    it('writes no ledger entry for a reservation', function () {
        // §23.1 has no reserve type and §23.3 makes it a status. An entry here
        // would be the ledger claiming value moved when none did.
        $before = LedgerEntry::query()->count();

        auditReserve();

        expect(LedgerEntry::query()->count())->toBe($before)
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('1000.00')
            ->and($this->wallet->reserved->toDecimal())->toBe('300.00');
    });

    it('writes no ledger entry for a release either', function () {
        $claim = auditReserve();

        $before = LedgerEntry::query()->count();

        app(WalletService::class)->release($claim);

        expect(LedgerEntry::query()->count())->toBe($before)
            ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
    });

    it('writes one the moment a capture makes value leave', function () {
        // The line: value crossing the wallet's edge is what the ledger is for.
        $claim = auditReserve();

        $before = LedgerEntry::query()->count();

        app(WalletService::class)->capture($claim->fresh());

        expect(LedgerEntry::query()->count())->toBe($before + 1)
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('700.00');
    });
});

describe('the audit record for bucket movement', function () {
    it('records the reservation with everything needed to account for it', function () {
        $staff = User::factory()->staff()->create();

        $claim = auditReserve(actor: $staff);

        $event = auditEvents($claim)->firstOrFail();

        expect($event->wallet_id)->toBe($this->wallet->id)
            ->and($event->business_account_id)->toBe($this->account->id)
            ->and($event->amount->toDecimal())->toBe('300.00')
            ->and($event->currency_code)->toBe('BDT')
            ->and($event->source)->toBe('test')
            ->and($event->actor_id)->toBe($staff->id)
            ->and($event->reason)->toBe('A charge is coming.')
            ->and($event->idempotency_key)->toBe('audit:300.00')
            ->and($event->occurred_at)->not->toBeNull()
            ->and($event->ledger_entry_id)->toBeNull();
    });

    it('records what the bucket held on either side of it', function () {
        // "Previous and resulting state" — the thing a mutable status column
        // cannot answer once it has moved on.
        $claim = auditReserve();

        $event = auditEvents($claim)->firstOrFail();

        expect($event->bucket)->toBe('reserved')
            ->and($event->bucket_before->toDecimal())->toBe('0.00')
            ->and($event->bucket_after->toDecimal())->toBe('300.00')
            // Nothing left the wallet, and the record says so.
            ->and($event->total_before->toDecimal())->toBe('1000.00')
            ->and($event->total_after->toDecimal())->toBe('1000.00');
    });

    it('keeps the reservation on the record after it is released', function () {
        /*
         * The whole point. `wallet_transactions.status` now says Cancelled and
         * the bucket is empty; without this, nothing anywhere would show that
         * three hundred taka was ever spoken for.
         */
        $claim = auditReserve();

        app(WalletService::class)->release($claim);

        $events = auditEvents($claim);

        expect($events)->toHaveCount(2)
            ->and($events[0]->from_status)->toBeNull()
            ->and($events[0]->to_status)->toBe(WalletTransactionStatus::Pending)
            ->and($events[1]->from_status)->toBe(WalletTransactionStatus::Pending)
            ->and($events[1]->to_status)->toBe(WalletTransactionStatus::Cancelled)
            ->and($events[1]->bucket_before->toDecimal())->toBe('300.00')
            ->and($events[1]->bucket_after->toDecimal())->toBe('0.00')
            ->and($events[1]->total_after->toDecimal())->toBe('1000.00');
    });

    it('links a capture to the entry it produced', function () {
        // The join between the two records: this is where bucket movement
        // becomes value movement, and both halves say so.
        $claim = auditReserve();

        app(WalletService::class)->capture($claim->fresh());

        $closing = auditEvents($claim)->last();
        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($closing->to_status)->toBe(WalletTransactionStatus::Settled)
            ->and($closing->ledger_entry_id)->toBe($entry->id)
            ->and($closing->total_before->toDecimal())->toBe('1000.00')
            ->and($closing->total_after->toDecimal())->toBe('700.00')
            ->and($entry->debit->toDecimal())->toBe('300.00');
    });

    it('records a plain posting too, so the history has no special cases', function () {
        $transaction = app(WalletService::class)->credit(
            $this->wallet->refresh(),
            LedgerTransactionType::TopUpCredit,
            Money::fromDecimal('50.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'Another top-up'),
        );

        $event = auditEvents($transaction)->firstOrFail();

        expect($event->to_status)->toBe(WalletTransactionStatus::Settled)
            ->and($event->bucket)->toBeNull()
            ->and($event->total_before->toDecimal())->toBe('1000.00')
            ->and($event->total_after->toDecimal())->toBe('1050.00')
            ->and($event->ledger_entry_id)->not->toBeNull();
    });
});

describe('immutability', function () {
    it('refuses to be edited through the model', function () {
        $event = auditEvents(auditReserve())->firstOrFail();

        expect(fn () => $event->forceFill(['to_status' => 'settled'])->save())
            ->toThrow(RuntimeException::class);
    });

    it('refuses an update that goes around the model entirely', function () {
        // The guard that matters: the query builder fires no model events, and
        // neither does a psql prompt.
        $event = auditEvents(auditReserve())->firstOrFail();

        expect(fn () => DB::transaction(fn () => DB::table('wallet_transaction_events')
            ->where('id', $event->id)
            ->update(['to_status' => 'settled'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a delete that goes around the model entirely', function () {
        $event = auditEvents(auditReserve())->firstOrFail();

        expect(fn () => DB::transaction(fn () => DB::table('wallet_transaction_events')
            ->where('id', $event->id)
            ->delete()))
            ->toThrow(QueryException::class);
    });
});

it('leaves no event behind when the posting it describes fails', function () {
    /*
     * The events are written in the same transaction as the change. A refused
     * reservation must not leave a record saying money was set aside.
     */
    $before = WalletTransactionEvent::query()->count();

    expect(fn () => app(WalletService::class)->reserve(
        $this->wallet->refresh(),
        LedgerTransactionType::ServiceFeeDebit,
        Money::fromDecimal('5000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'More than there is'),
    ))->toThrow(WalletOperationRefused::class);

    expect(WalletTransactionEvent::query()->count())->toBe($before)
        ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
});
