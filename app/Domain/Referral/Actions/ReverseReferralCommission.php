<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Take a commission back (§25.5, D24, P7-43).
 *
 * **Only ever by a compensating entry.** Nothing already posted is edited:
 *
 *   - a commission not yet paid is **cancelled** — it never reached a wallet,
 *     so there is nothing to post;
 *   - a paid one is answered by a `referral_reward_reversal` debit naming the
 *     entry it corrects, with its own idempotency key, and becomes
 *     **reversed**;
 *   - a paid one whose wallet no longer holds the amount becomes **reversal
 *     owed** — decided and recorded, never taken into a negative balance — and
 *     the sweep posts it once the wallet can carry it.
 *
 * Serialised on the commission row; a second reversal finds nothing left to
 * do. Audited with who, when, the status before and after, and why.
 */
class ReverseReferralCommission
{
    public function __construct(
        protected DatabaseManager $database,
        protected WalletService $wallet,
        protected OpenWallet $wallets,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws ReferralRefused when there is nothing left to reverse
     */
    public function handle(ReferralCommission $commission, ReversalCause $cause, string $reason, ?User $actor = null): ReferralCommission
    {
        $now = CarbonImmutable::now();

        [$reversed, $before] = $this->database->transaction(function () use ($commission, $cause, $reason, $actor, $now) {
            /** @var ReferralCommission $locked */
            $locked = ReferralCommission::query()->lockForUpdate()->findOrFail($commission->id);
            $before = $locked->status;

            if (! in_array($before, [CommissionStatus::Pending, CommissionStatus::Paid, CommissionStatus::ReversalOwed], true)) {
                throw ReferralRefused::nothingToReverse();
            }

            // The first decision is what is recorded; a retry of an owed
            // reversal keeps it.
            if ($before !== CommissionStatus::ReversalOwed) {
                $locked->forceFill([
                    'reversed_at' => $now,
                    'reversal_reason' => $reason,
                    'reversal_cause' => $cause,
                    'reversed_by' => $actor?->id,
                ]);
            }

            if ($before === CommissionStatus::Pending) {
                $locked->transitionTo(CommissionStatus::Cancelled)->save();

                return [$locked, $before];
            }

            try {
                $transaction = $this->wallet->debit(
                    $this->wallets->handle(BusinessAccount::query()->findOrFail($locked->beneficiary_account_id)),
                    LedgerTransactionType::ReferralRewardReversal,
                    $locked->amount,
                    new PostingContext(
                        source: 'referral',
                        description: $locked->isJoiningReward()
                            ? 'Joining reward reversed'
                            : 'Referral commission reversed — level '.$locked->level,
                        idempotencyKey: $locked->reversalKey(),
                        reason: (string) $locked->reversal_reason,
                        actorId: $actor?->id,
                        direction: LedgerDirection::Debit,
                        correctsLedgerEntryId: LedgerEntry::query()
                            ->where('wallet_transaction_id', $locked->wallet_transaction_id)
                            ->value('id'),
                    ),
                );
            } catch (WalletOperationRefused) {
                if ($before === CommissionStatus::Paid) {
                    $locked->transitionTo(CommissionStatus::ReversalOwed)->save();
                }

                return [$locked, $before];
            }

            $locked->transitionTo(CommissionStatus::Reversed);
            $locked->forceFill(['reversal_wallet_transaction_id' => $transaction->id])->save();

            return [$locked, $before];
        });

        if ($reversed->status !== $before) {
            $this->audit->handle(new AuditEntry(
                action: 'referral.commission_reversed',
                actorId: $actor?->id,
                actorType: $actor === null ? 'system' : 'user',
                auditableType: ReferralCommission::class,
                auditableId: $reversed->id,
                before: ['status' => $before->value],
                after: [
                    'status' => $reversed->status->value,
                    'amount' => $reversed->amount->jsonSerialize(),
                    'level' => $reversed->level,
                    'cause' => $reversed->reversal_cause?->value,
                ],
                reason: $reversed->reversal_reason,
                accountId: $reversed->beneficiary_account_id,
                module: 'referral',
                isSensitive: true,
            ));
        }

        $commission->setRawAttributes($reversed->getAttributes(), sync: true);

        return $commission;
    }
}
