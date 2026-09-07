<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycUpdateRequested;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Asks a trading business for fresh KYC (§7.2, §7.4).
 *
 * A document has expired, a rule has changed, a periodic re-verification falls
 * due. §7.2 gives an authorised user this power over accounts that are
 * *already active*, which is what separates it from
 * {@see StartKycResubmission}: that follows a reviewer sending an applicant back
 * during onboarding, this reaches a business that has been trading for a year.
 *
 * **The account keeps trading.** Opening the round does not touch its status.
 * Asking a live business for a document and stopping its orders in the same
 * breath would punish it for a request it has not had a chance to answer;
 * §7.4's restriction is what applies if the deadline then passes, and only when
 * an administrator has turned it on.
 *
 * The deadline may be given explicitly, which is the point of §7.2 offering it
 * beside the global setting — "we need this within seven days" is a different
 * instruction from the standing window, and a request that could only use the
 * default would have no way to say so.
 */
class RequestKycUpdate
{
    public function __construct(
        protected KycDeadlines $deadlines,
        protected CaptureRoundRequirements $captureRequirements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  string  $reason  internal: why we asked
     * @param  string  $instructions  what the account holder is told to do
     * @param  CarbonImmutable|null  $deadline  overrides the configured window
     * @param  array<int, string>|null  $documentTypeIds  narrows the round to
     *                                                    these documents; null
     *                                                    asks for everything
     *                                                    that applies
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        BusinessAccount $account,
        User $requestedBy,
        string $reason,
        string $instructions,
        ?CarbonImmutable $deadline = null,
        ?array $documentTypeIds = null,
    ): KycSubmission {
        $reason = trim($reason);
        $instructions = trim($instructions);

        if ($reason === '') {
            throw new InvalidArgumentException('A KYC update request needs an internal reason.');
        }

        if ($instructions === '') {
            // Without this the account holder is told to update their KYC and
            // not what is wrong with it — which produces the same documents
            // back, and a second request.
            throw new InvalidArgumentException(
                'A KYC update request needs instructions for the account holder.'
            );
        }

        if ($deadline !== null && $deadline->isPast()) {
            throw new InvalidArgumentException(
                'A KYC update deadline cannot be in the past.'
            );
        }

        if ($documentTypeIds !== null && $documentTypeIds === []) {
            // An empty selection is a request for nothing at all. Silently
            // treating it as "everything" would send an account a demand for
            // documents the requester had just deselected.
            throw new InvalidArgumentException(
                'Select at least one document to ask for.'
            );
        }

        $submission = $this->database->transaction(
            fn () => $this->open(
                $account,
                $requestedBy,
                $reason,
                $instructions,
                $deadline,
                $documentTypeIds,
            )
        );

        /*
         * Outside the transaction: a notification for a round that then rolled
         * back would send a business chasing a request that does not exist.
         *
         * Not optional (D20). A KYC request is an account-status message, and
         * one that can be switched off is one an account can miss and then be
         * restricted for missing.
         */
        Notification::send($account->owner, new KycUpdateRequested($submission));

        return $submission;
    }

    /**
     * @param  array<int, string>|null  $documentTypeIds
     */
    protected function open(
        BusinessAccount $account,
        User $requestedBy,
        string $reason,
        string $instructions,
        ?CarbonImmutable $deadline,
        ?array $documentTypeIds = null,
    ): KycSubmission {
        /*
         * The **account** is locked, not the latest round.
         *
         * Locking the latest submission looks equivalent and is not: two
         * concurrent requests both lock round 1, the winner inserts round 2,
         * and the loser wakes still holding round 1 as "the latest" — because
         * `FOR UPDATE` re-checks the rows it locked, not the ordering of a
         * query it already ran. It would then try to insert a second round 2.
         * Locking the account serialises the whole read-decide-write.
         */
        BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

        $latest = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->orderByDesc('round')
            ->first();

        if ($latest === null) {
            throw new InvalidArgumentException(
                'This account has never submitted KYC, so there is nothing to update.'
            );
        }

        if ($latest->status->isEditable() || $latest->status->awaitsReview()) {
            /*
             * A round is already open, or waiting on us.
             *
             * Opening a second would give the account holder two rounds and no
             * way to know which one the request refers to — and if ours is
             * waiting on a reviewer, the delay is ours, not theirs.
             */
            throw new InvalidArgumentException(
                'This account already has a KYC round in progress.'
            );
        }

        try {
            $submission = $this->createRound($account, $latest->round + 1, $requestedBy, $reason, $instructions, $deadline);
        } catch (UniqueConstraintViolationException) {
            /*
             * The unique index on (business_account_id, round) — the backstop
             * behind the lock above. A round number cannot exist twice, so a
             * duplicate request under any concurrency the lock did not cover
             * is refused at the database rather than doubling a deadline or a
             * notification.
             */
            throw new InvalidArgumentException(
                'This account already has a KYC round in progress.'
            );
        }

        /*
         * What the round asks for, fixed at the moment it opens (§7.2).
         *
         * A narrowed request has to actually narrow something: a selection that
         * matches nothing applicable would open a round demanding no documents,
         * which the account holder could never satisfy or clear.
         */
        $captured = $this->captureRequirements->handle(
            $submission,
            onlyTypeIds: $documentTypeIds,
        );

        if ($documentTypeIds !== null && $captured === 0) {
            throw new InvalidArgumentException(
                'None of the selected documents apply to this account.'
            );
        }

        $this->audit->handle(new AuditEntry(
            action: 'kyc.update_requested',
            actorId: $requestedBy->id,
            auditableType: KycSubmission::class,
            auditableId: $submission->id,
            after: [
                'round' => $submission->round,
                'deadline_at' => $submission->deadline_at?->toIso8601String(),
            ],
            reason: $reason,
            note: $instructions,
            accountId: $account->id,
            module: 'kyc',
        ));

        return $submission;
    }

    protected function createRound(
        BusinessAccount $account,
        int $round,
        User $requestedBy,
        string $reason,
        string $instructions,
        ?CarbonImmutable $deadline,
    ): KycSubmission {
        return KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => $round,

            // An explicit deadline wins over the configured window; null when
            // neither is set, which leaves the request open-ended rather than
            // inventing an expiry nobody chose (§7.4).
            'deadline_at' => $deadline ?? $this->deadlines->deadlineFrom(),

            'requested_at' => now(),
            'requested_by' => $requestedBy->id,
            'request_reason' => $reason,
            'request_instructions' => $instructions,
        ]);
    }
}
