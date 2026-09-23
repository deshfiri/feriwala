<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Puts a posted entry right (§23.2).
 *
 * §23.2 allows exactly three ways to correct the ledger — an adjustment entry, a
 * reversal entry, a corrective entry — and none of them is editing what is
 * already there. The original stays untouched and a **new** entry answers it, so
 * the wrong figure and the putting-right of it are both on the record. That is
 * the difference between a ledger and a balance.
 *
 * A reversal is the opposite movement of the same amount, pointing at the entry
 * it reverses. An adjustment is a movement somebody decided on, pointing at
 * nothing in particular, and carrying the reason they gave.
 *
 * Both are idempotent. A reversal's key is derived from the entry it reverses,
 * so asking twice returns the first one rather than reversing twice — which
 * would put the money back and then take it away again, and leave a ledger that
 * reads as if two different things happened.
 */
class CorrectLedgerEntry
{
    public function __construct(
        protected WalletService $wallet,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * Undo an entry with its mirror image (§23.2's reversal entry).
     */
    public function reverse(LedgerEntry $original, User $actor, string $reason): WalletTransaction
    {
        if (blank($reason)) {
            throw WalletOperationRefused::reasonRequired(LedgerTransactionType::ManualAdjustment);
        }

        /** @var Wallet $wallet */
        $wallet = Wallet::query()->findOrFail($original->wallet_id);

        $type = $this->reversalTypeFor($original->type);
        $amount = $original->amount();

        $context = new PostingContext(
            source: 'correction',
            description: 'Reversal of '.$original->reference,
            // Derived from what is being reversed, so a second attempt returns
            // the first reversal instead of making another one.
            idempotencyKey: 'reversal:'.$original->reference,
            reason: $reason,
            actorId: $actor->id,
            paymentId: $original->payment_id,
            userId: $original->user_id,

            // The opposite of whatever the original did.
            direction: $original->isCredit() ? LedgerDirection::Debit : LedgerDirection::Credit,
            correctsLedgerEntryId: $original->id,
        );

        $transaction = $original->isCredit()
            ? $this->wallet->debit($wallet, $type, $amount, $context)
            : $this->wallet->credit($wallet, $type, $amount, $context);

        $this->record($actor, $wallet, $transaction, $reason, [
            'reverses' => $original->reference,
            'amount' => $amount->toDecimal(),
        ]);

        return $transaction;
    }

    /**
     * Move money by hand (§23.1's manual adjustment).
     *
     * The one operation where a person, rather than an event, decides a balance
     * should change — so it carries both a reason and the name of whoever made
     * it, and the posting service refuses it without them.
     */
    public function adjust(
        Wallet $wallet,
        User $actor,
        Money $amount,
        LedgerDirection $direction,
        string $reason,
        ?string $internalNote = null,
    ): WalletTransaction {
        $context = new PostingContext(
            source: 'manual',
            description: $direction->isCredit()
                ? 'Manual adjustment (credit)'
                : 'Manual adjustment (debit)',
            reason: $reason,
            internalNote: $internalNote,
            actorId: $actor->id,
            direction: $direction,
        );

        $transaction = $direction->isCredit()
            ? $this->wallet->credit($wallet, LedgerTransactionType::ManualAdjustment, $amount, $context)
            : $this->wallet->debit($wallet, LedgerTransactionType::ManualAdjustment, $amount, $context);

        $this->record($actor, $wallet, $transaction, $reason, [
            'direction' => $direction->value,
            'amount' => $amount->toDecimal(),
        ]);

        return $transaction;
    }

    /**
     * The type a reversal of this entry should carry.
     *
     * §23.1 names two reversals of its own. Everything else is put right as a
     * corrective entry, which is what a manual adjustment against a specific
     * entry is.
     */
    protected function reversalTypeFor(LedgerTransactionType $type): LedgerTransactionType
    {
        return match ($type) {
            LedgerTransactionType::CommissionCredit => LedgerTransactionType::CommissionReversal,

            LedgerTransactionType::ReferralRewardCredit,
            LedgerTransactionType::JoiningRewardCredit => LedgerTransactionType::ReferralRewardReversal,

            default => LedgerTransactionType::ManualAdjustment,
        };
    }

    /**
     * @param  array<string, mixed>  $after
     */
    protected function record(
        User $actor,
        Wallet $wallet,
        WalletTransaction $transaction,
        string $reason,
        array $after,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: 'wallet.'.$transaction->type->value,
            actorId: $actor->id,
            auditableType: WalletTransaction::class,
            auditableId: $transaction->id,
            after: $after + ['reference' => $transaction->reference],
            reason: $reason,
            accountId: $wallet->business_account_id,
            module: 'wallet',
            // Somebody moved money by hand. That is what a reconciliation goes
            // looking for first.
            isSensitive: true,
        ));
    }
}
