<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Answer a damage charge with its opposite, never by editing it (D35).
 *
 * The original charge, its wallet transaction and its ledger entry stay exactly
 * as they were written. A **reversal row** is added that points at the charge,
 * and what the wallet took is given back by a new credit pointing at the ledger
 * entry it answers.
 *
 * **Typed balances are preserved.** The debit spent platform credit and
 * withdrawable money in some proportion, which its transaction remembers. The
 * credit returns exactly those amounts to exactly those kinds — platform credit
 * is not turned into withdrawable money by being refunded.
 *
 * A Banij-borne charge, or one that was only owed, moved nothing in a wallet and
 * is reversed in the accounting alone.
 *
 * Once: the reversal's key is derived from the charge's, so a second request
 * finds the first and a charge already reversed is not reversed again.
 */
class ReverseDamageCharge
{
    public function __construct(
        protected WalletService $wallet,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return DamageCharge|null the reversal row, or null when the charge was already answered
     */
    public function handle(DamageCharge $charge, string $reason, ?User $actor = null): ?DamageCharge
    {
        $key = 'reversal:'.$charge->idempotency_key;

        try {
            return $this->database->transaction(function () use ($charge, $reason, $actor, $key) {
                /** @var DamageCharge $locked */
                $locked = DamageCharge::query()->lockForUpdate()->findOrFail($charge->id);

                if ($locked->isReversal() || $locked->status === ReturnChargeStatus::Reversed) {
                    return null;
                }

                $transactionId = $this->giveBack($locked, $reason, $actor, $key);

                $reversal = DamageCharge::create([
                    'order_damage_id' => $locked->order_damage_id,
                    'business_account_id' => $locked->business_account_id,
                    'kind' => $locked->kind,
                    'entry_type' => DamageCharge::REVERSAL,
                    'reverses_charge_id' => $locked->id,
                    'bearer' => $locked->bearer,
                    'amount' => $locked->amount,
                    'currency_code' => $locked->currency_code,
                    'status' => $transactionId === null ? ReturnChargeStatus::Recorded : ReturnChargeStatus::Posted,
                    'wallet_transaction_id' => $transactionId,
                    'reason' => $reason,
                    'actor_id' => $actor?->id,
                    'idempotency_key' => $key,
                    'posted_at' => CarbonImmutable::now(),
                ]);

                $locked->forceFill(['status' => ReturnChargeStatus::Reversed])->save();

                return $reversal;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Return what the wallet took, to the kinds of money it took it from.
     *
     * @return int|null the first credit's wallet transaction, or null when no wallet money moved
     */
    protected function giveBack(DamageCharge $charge, string $reason, ?User $actor, string $key): ?int
    {
        if ($charge->wallet_transaction_id === null) {
            return null;
        }

        /** @var WalletTransaction $original */
        $original = WalletTransaction::query()->findOrFail($charge->wallet_transaction_id);

        $platform = $original->platform_credit_amount;
        $withdrawable = $charge->amount->minus($platform);

        $first = null;

        foreach ([[BalanceType::PlatformCredit, $platform], [BalanceType::Withdrawable, $withdrawable]] as [$kind, $part]) {
            /** @var Money $part */
            if (! $part->isPositive()) {
                continue;
            }

            // Each part answers the entry that took that kind of money.
            $correctsEntry = LedgerEntry::query()
                ->where('wallet_transaction_id', $original->id)
                ->where('balance_type', $kind->value)
                ->orderBy('id')
                ->value('id');

            $credit = $this->wallet->credit(
                $original->wallet,
                LedgerTransactionType::ReturnAdjustment,
                $part,
                new PostingContext(
                    source: 'order_damage',
                    description: 'Reversed — '.$charge->kind->label().' on '.$charge->damage->reference,
                    idempotencyKey: $key.':'.$kind->value,
                    reason: $reason,
                    actorId: $actor?->id,
                    direction: LedgerDirection::Credit,
                    correctsLedgerEntryId: $correctsEntry,
                ),
                $kind,
            );

            $first ??= $credit->id;
        }

        return $first;
    }
}
