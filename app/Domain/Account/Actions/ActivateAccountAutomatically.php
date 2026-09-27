<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Activates an account the moment it has earned it (D27, amending D22).
 *
 * The normal path has no second decision to make. KYC has been approved by a
 * person, the activation payment has been verified server-side against the
 * exact amount owed, and the owner's email and mobile are verified — at which
 * point there is nothing left for an administrator to weigh, and asking one to
 * click approve only meant an account that had done everything right waited
 * for office hours. So the platform acts on its own conditions.
 *
 * **This is not a new activation path.** It is the existing
 * {@see ActivateAccount} — the same transaction, the same row lock, the same
 * re-check of {@see ActivationRequirements}, the same wallet, deposit
 * obligation, subscription and referral commissions — reached without a human
 * in front of it. Everything §5.1 required is still required; only who decides
 * has changed, and the audit entry says so by recording no actor.
 *
 * Manual activation is untouched and still available. It remains the route for
 * an account whose activation has been **held** (D27): a hold is a deliberate,
 * reasoned decision by a named member of staff that this one should be looked
 * at, and it turns the automatic path off for that account without preventing a
 * reviewer from activating it themselves.
 *
 * Safe to call as often as anything wants to. It is reached from the settlement
 * of a payment, which is itself retried by the gateway's IPN, by the browser
 * returning, and by the reconciliation sweep — three callers for the same
 * event, all of which may arrive at once. Whichever gets there first activates;
 * the rest find an account that is already active and do nothing. It never
 * throws: a failure to activate must never roll back the payment that was
 * genuinely received.
 */
class ActivateAccountAutomatically
{
    public function __construct(
        protected EvaluateActivationReadiness $readiness,
        protected ActivateAccount $activate,
        protected RecordAuditLog $audit,
        protected LogManager $log,
    ) {}

    /**
     * @param  string  $trigger  what made this worth re-checking, recorded on the
     *                           account's status history
     * @return bool whether the account is active afterwards — including when it
     *              already was
     */
    public function handle(BusinessAccount $account, string $trigger): bool
    {
        if ($account->isActivated()) {
            return true;
        }

        /*
         * The gate first, always, and whatever happens next.
         *
         * It is what keeps `ApprovalPending` and `approval_pending_at` honest,
         * and a held account still belongs in the queue — more so than an
         * ordinary one, since a person now has to look at it. Skipping this
         * for held accounts would hide them from the very reviewers the hold
         * exists to summon.
         */
        try {
            $isReady = $this->readiness->handle($account, $trigger);

            if (! $isReady) {
                return false;
            }

            // Reloaded because the readiness pass moved the row, and because a
            // hold may have been placed since the caller loaded it.
            $account->refresh();

            if ($account->activationIsHeld()) {
                $this->recordHeldBack($account, $trigger);

                return false;
            }

            $this->activate->handle($account, approvedBy: null, note: null);

            return true;
        } catch (ActivationBlocked) {
            /*
             * Between the readiness check and the row lock something moved —
             * most often a second settlement callback for the same payment
             * getting there first, in which case the account is active and this
             * one has nothing to do. Anything else genuinely is not ready, and
             * the account keeps its place at the gate for a reviewer.
             */
            return $account->fresh()?->isActivated() ?? false;
        } catch (Throwable $throwable) {
            /*
             * Never allowed to fail the caller. The money arrived; an account
             * that stays at the approval gate can be activated by a reviewer in
             * a moment, while a settlement rolled back because a notification
             * queue was down is a payment the platform has taken and denied.
             */
            $this->log->channel('payment')->error('Could not activate an account automatically', [
                'business_account' => $account->id,
                'trigger' => $trigger,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Say plainly, once, that the platform would have activated this account
     * and did not.
     *
     * Without this the hold is invisible in the record: the account simply sits
     * at the gate looking like every other one waiting, and nobody can tell
     * afterwards whether it was held or merely unlucky in a queue.
     */
    protected function recordHeldBack(BusinessAccount $account, string $trigger): void
    {
        // Once per hold, not once per callback. The same settlement is
        // re-announced by the IPN, the browser return and the reconciliation
        // sweep, and three identical "still held" lines say nothing the first
        // one did not.
        $alreadyRecorded = AuditLog::query()
            ->where('action', 'account.activation_held_back')
            ->where('auditable_type', BusinessAccount::class)
            ->where('auditable_id', $account->id)
            ->where('created_at', '>=', $account->activation_held_at)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $this->audit->handle(new AuditEntry(
            action: 'account.activation_held_back',
            actorType: 'system',
            auditableType: BusinessAccount::class,
            auditableId: $account->id,
            after: [
                'status' => AccountStatus::ApprovalPending->value,
                'trigger' => $trigger,
            ],
            reason: 'Every activation condition is met, but this account is held for manual review.',
            note: $account->activation_hold_reason,
            accountId: $account->id,
            module: 'account',
        ));
    }
}
