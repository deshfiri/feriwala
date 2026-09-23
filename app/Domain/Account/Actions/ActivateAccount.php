<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Referral\Actions\CalculateReferralCommissions;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Notifications\Account\AccountActivated;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Database\DatabaseManager;

/**
 * Activates an account after administrative approval (§5.1, §44).
 *
 * The last gate, and the only route to {@see AccountStatus::Active}. It re-checks
 * every precondition rather than trusting that the approval queue already did —
 * the queue is a view, this is the decision, and the two can drift apart while
 * an approval sits waiting.
 *
 * Everything moves in one transaction: the account status, the subscription
 * becoming active, and the account's pointer to it. A subscription activated
 * without the account, or an account activated with no live subscription, would
 * both leave someone unable to trade while apparently able to.
 */
class ActivateAccount
{
    public function __construct(
        protected ActivationRequirements $requirements,
        protected ChangeAccountStatus $changeStatus,
        protected AdvanceToApprovalGate $gate,
        protected RecordAuditLog $audit,
        protected OpenWallet $wallets,
        protected CaptureDepositObligation $obligations,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected CalculateReferralCommissions $referralCommissions,
    ) {}

    /**
     * @param  int  $approvedBy  the administrator taking responsibility
     *
     * @throws ActivationBlocked
     */
    public function handle(BusinessAccount $account, int $approvedBy, ?string $note = null): BusinessAccount
    {
        // Serialised per account. Two reviewers deciding at the same moment must
        // resolve to one outcome, and the requirements re-check below has to
        // happen inside that serialisation to be worth anything.
        $activated = $this->lock->run(
            key: 'account:activation:'.$account->id,
            callback: fn () => $this->activate($account, $approvedBy, $note),
            ttlSeconds: 30,
            waitSeconds: 10,
        );

        // Outside the transaction: a queued mail for a change that then rolled
        // back is worse than a slightly later one.
        $account->owner?->notify(new AccountActivated($note));

        return $activated;
    }

    /**
     * @throws ActivationBlocked
     */
    protected function activate(BusinessAccount $account, int $approvedBy, ?string $note): BusinessAccount
    {
        return $this->database->transaction(function () use ($account, $approvedBy, $note) {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            // Re-checked under the row lock, not before it. Between the queue
            // rendering and this moment a payment can be refunded or a KYC
            // approval withdrawn, and the queue is a view — this is the decision.
            $unmet = $this->requirements->unmet($locked);

            if ($unmet !== []) {
                throw ActivationBlocked::because($unmet);
            }

            $from = $locked->status;

            // §5.3 routes activation through approval. An account that reached
            // this point another way is moved onto the approved step first, so
            // the history shows the gate rather than skipping it.
            $this->gate->handle($locked, $approvedBy, 'All activation conditions met.');

            $this->changeStatus->handle($locked, new AccountStatusChange(
                to: AccountStatus::Active,
                changedBy: $approvedBy,
                reason: 'Activation approved.',
                userVisibleNote: $note ?? 'Your account is now active.',
            ));

            $this->activateSubscription($locked);

            /*
             * "Every Active Account will have a Wallet and Financial Ledger"
             * (§23). Opened inside the same transaction as the activation, so
             * an account can never be active without one — and idempotent, so
             * an account activated twice still has exactly one wallet.
             *
             * It opens empty. Every balance starts at zero and moves only
             * through the ledger, so there is no figure without an entry
             * explaining it.
             */
            $this->wallets->handle($locked);

            /*
             * And what it is required to hold (§24.1, P2-13).
             *
             * Captured here rather than read whenever it is needed: the rule in
             * force today becomes this account's obligation, and a rule edited
             * next June must not reach back and change what it agreed to. An
             * account with no rule applying captures nothing and is held to
             * nothing, which is also an answer.
             */
            $this->obligations->handle($locked, CaptureDepositObligation::ACTIVATION);

            /*
             * The multi-level referral trigger (D24): activation after the
             * verified combined payment. Written here, in the activation's own
             * transaction, as an event and its commissions — the outbox — so
             * an activation that rolls back leaves no commission behind, and
             * one that commits cannot be paid for twice. Nothing reaches a
             * wallet until after the commit.
             */
            $this->referralCommissions->forActivation($locked);

            // The account has left the approval queue. Clearing the stamp keeps
            // the queue's sort key meaning only "waiting for review".
            $locked->forceFill(['approval_pending_at' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.activated',
                actorId: $approvedBy,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => AccountStatus::Active->value],
                reason: 'Activation approved.',
                note: $note,
                accountId: $locked->id,
                module: 'account',
            ));

            $account->setRawAttributes($locked->getAttributes(), sync: true);

            return $account;
        });
    }

    /**
     * Bring the paid-for subscription to life.
     *
     * Its term starts now rather than at purchase — someone whose approval sat
     * in a queue for three days should not lose three days of the package they
     * paid for.
     */
    protected function activateSubscription(BusinessAccount $account): void
    {
        $subscription = UserPackage::query()
            ->where('business_account_id', $account->id)
            ->where('status', UserPackageStatus::PendingPayment)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($subscription === null) {
            return;
        }

        /*
         * The term comes from what the account **bought**, not from the package
         * as it stands now (§8.3).
         *
         * Reading the live row meant an administrator shortening `validity_days`
         * between purchase and approval quietly sold a shorter year than the
         * one that was paid for — and approval can be days later, which is
         * exactly the window in which a price list gets edited.
         */
        $terms = $subscription->terms();
        $validityDays = $terms?->validityDays;
        $graceDays = $terms === null ? 0 : ($terms->gracePeriodDays ?? 0);

        $startedAt = now();

        // The status machine, not an assignment: PendingPayment may become
        // Active, and this is the only place it does (§5.1).
        $subscription->transitionTo(UserPackageStatus::Active);

        $subscription->forceFill([
            'started_at' => $startedAt,

            // Null validity is a term that does not end. Distinct from zero,
            // which nothing sets and which would expire on the day it began.
            'expires_at' => $validityDays === null
                ? null
                : $startedAt->addDays($validityDays),

            'grace_ends_at' => $validityDays === null
                ? null
                : $startedAt->addDays($validityDays)->addDays($graceDays),
        ])->save();

        $account->forceFill(['current_user_package_id' => $subscription->id])->save();
    }
}
