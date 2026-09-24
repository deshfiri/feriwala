<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\WalletService;
use App\Notifications\Referral\ReferralCommissionPaid;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Pay one calculated commission into its beneficiary's wallet (D24, P7-43).
 *
 * Only a **pending** commission whose holding period has passed, re-read under
 * a row lock, so two workers releasing the same one pay it once — and the
 * posting carries the commission's own idempotency key, so even a retry after a
 * crash between the credit and the status change posts once.
 *
 * The beneficiary is asked again at the moment of payment: a business that may
 * not be paid now — suspended, say, under a version that does not pay
 * suspended accounts — waits, still pending, and is tried again by the sweep.
 * One that has closed is never paid; the commission is cancelled.
 */
class ReleaseReferralCommission
{
    public function __construct(
        protected DatabaseManager $database,
        protected WalletService $wallet,
        protected OpenWallet $wallets,
    ) {}

    public function handle(int $commissionId, ?CarbonImmutable $now = null): ?ReferralCommission
    {
        $now ??= CarbonImmutable::now();

        $paid = $this->database->transaction(function () use ($commissionId, $now) {
            /** @var ReferralCommission|null $commission */
            $commission = ReferralCommission::query()->lockForUpdate()->find($commissionId);

            if ($commission === null
                || $commission->status !== CommissionStatus::Pending
                || $commission->available_at->greaterThan($now)) {
                return null;
            }

            /** @var BusinessAccount $beneficiary */
            $beneficiary = BusinessAccount::query()->findOrFail($commission->beneficiary_account_id);

            if ($beneficiary->status === AccountStatus::Closed) {
                $commission->transitionTo(CommissionStatus::Cancelled);
                $commission->forceFill([
                    'reversed_at' => $now,
                    'reversal_reason' => 'The beneficiary account closed before the commission was due.',
                    'reversal_cause' => ReversalCause::BeneficiaryClosed,
                ])->save();

                return null;
            }

            if (! $commission->plan->qualifiesStatus($beneficiary->status)) {
                return null;
            }

            $transaction = $this->wallet->credit(
                $this->wallets->handle($beneficiary),
                $commission->creditType(),
                $commission->amount,
                new PostingContext(
                    source: 'referral',
                    description: $commission->isJoiningReward()
                        ? 'Joining reward'
                        : 'Referral commission — level '.$commission->level,
                    idempotencyKey: $commission->idempotencyKey(),
                ),
            );

            $commission->transitionTo(CommissionStatus::Paid);
            $commission->forceFill([
                'paid_at' => $now,
                'wallet_transaction_id' => $transaction->id,
            ])->save();

            return $commission;
        });

        if ($paid instanceof ReferralCommission) {
            // After the commit: a message about money that then rolled back is
            // worse than one a moment late.
            $paid->beneficiary->owner?->notify(new ReferralCommissionPaid(
                amount: $paid->amount->jsonSerialize(),
                level: $paid->level,
            ));
        }

        return $paid;
    }
}
