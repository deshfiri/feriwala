<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycReverificationCancelled;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Withdraws a re-verification nobody has answered yet (§7.2).
 *
 * Asked in error, superseded by a document that arrived another way, or
 * opened against the wrong account. Without this the only ways out are to
 * approve a round that was never submitted or reject a business that did
 * nothing wrong — and both are lies in the record.
 *
 * **The round stays.** It is evidence that we asked, and the business was
 * notified and possibly restricted while it stood; deleting it would erase
 * the explanation for both. It is marked cancelled, with a reason, and keeps
 * its place in the history.
 *
 * Only an unanswered round may be withdrawn. Once the business has submitted,
 * the decision belongs to a reviewer — approving, rejecting, or asking for a
 * correction — because someone has done the work and is owed an answer.
 *
 * Cancelling lifts every restriction this case imposed, immediately and by
 * construction: {@see KycRestrictions} reads only rounds that are still open,
 * and a cancelled round is not.
 */
class CancelKycReverification
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws InvalidArgumentException when the reason is empty, or the round
     *                                  has been answered or already withdrawn
     */
    public function handle(KycSubmission $submission, User $cancelledBy, string $reason): KycSubmission
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'Withdrawing a verification request needs a reason, which stays in the account history.'
            );
        }

        $cancelled = $this->database->transaction(function () use ($submission, $cancelledBy, $reason) {
            /** @var KycSubmission $locked */
            $locked = KycSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if (! $locked->isReverification()) {
                throw new InvalidArgumentException(
                    'Only a re-verification request can be withdrawn.'
                );
            }

            // Checked under the lock: two staff withdrawing at once resolve to
            // one winner, and the loser is told rather than writing a second
            // cancellation over the first one's reason.
            if ($locked->cancelled_at !== null) {
                throw new InvalidArgumentException('This request has already been withdrawn.');
            }

            if (in_array($locked->status, [KycStatus::Approved, KycStatus::Rejected], true)) {
                throw new InvalidArgumentException('This request has already been decided.');
            }

            if ($locked->status->awaitsReview()) {
                throw new InvalidArgumentException(
                    'This business has already submitted; decide the round rather than withdrawing it.'
                );
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy->id,
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'kyc.reverification_cancelled',
                actorId: $cancelledBy->id,
                auditableType: KycSubmission::class,
                auditableId: $locked->id,
                before: ['round' => $locked->round, 'cancelled' => false],
                after: [
                    'round' => $locked->round,
                    'cancelled' => true,
                    // The consequences this lifts, by name. No document path
                    // or identity field reaches an audit payload.
                    'consequences_lifted' => array_map(
                        fn ($consequence) => $consequence->value,
                        $locked->consequences(),
                    ),
                ],
                reason: $reason,
                accountId: $locked->business_account_id,
                module: 'kyc',
            ));

            $submission->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });

        /*
         * Outside the transaction, and only to the account holder: a business
         * that was told to re-verify — and may have been restricted for it —
         * is entitled to be told the requirement has gone. The internal reason
         * for withdrawing it is not sent (§7.3).
         */
        $cancelled->businessAccount?->owner?->notify(new KycReverificationCancelled);

        return $cancelled;
    }
}
