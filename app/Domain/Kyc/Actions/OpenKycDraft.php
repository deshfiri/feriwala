<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Batch\AssignKycBatch;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Database\DatabaseManager;

/**
 * Gets the draft an applicant should be filling in, creating one if needed.
 *
 * Returns the open draft when there is one, so revisiting the form does not
 * strand documents on an abandoned round. Locks while it looks, because two
 * tabs open at once would otherwise create two drafts and the applicant would
 * see their uploads disappear when they submitted the wrong one.
 */
class OpenKycDraft
{
    public function __construct(
        protected StartKycResubmission $startResubmission,
        protected CaptureRoundRequirements $captureRequirements,
        protected AssignKycBatch $batches,
        protected KycDeadlines $deadlines,
        protected DatabaseManager $database,
    ) {}

    public function handle(BusinessAccount $account): KycSubmission
    {
        return $this->database->transaction(function () use ($account) {
            $latest = KycSubmission::query()
                ->where('business_account_id', $account->id)
                ->orderByDesc('round')
                ->lockForUpdate()
                ->first();

            if ($latest === null) {
                $first = KycSubmission::create([
                    'business_account_id' => $account->id,
                    'status' => KycStatus::Draft,
                    'round' => 1,

                    // The §7.4 clock starts when the applicant is first able to
                    // act, not at registration — they cannot be late for
                    // something that was not yet open to them. Null when no
                    // deadline is configured.
                    'deadline_at' => $this->deadlines->deadlineFrom(),
                ]);

                return $this->withRequirements($first);
            }

            if ($latest->status->isEditable()) {
                /*
                 * Captured here as well as at creation, and idempotently.
                 * Rounds opened before requirement snapshots existed have none,
                 * and a form rendering nothing at all would be worse than one
                 * built from the configuration as it stands today.
                 */
                return $this->withRequirements($latest);
            }

            // Awaiting or holding a decision — the applicant should be looking
            // at it, not editing it.
            if ($latest->status->awaitsReview() || $latest->status === KycStatus::Approved) {
                return $latest;
            }

            return $this->withRequirements($this->startResubmission->handle($account));
        });
    }

    /**
     * The round, with what it asks for recorded against it (§7.2).
     */
    protected function withRequirements(KycSubmission $submission): KycSubmission
    {
        // Already pinned to a batch version: that version is the round.
        if ($submission->kyc_batch_version_id !== null) {
            return $submission;
        }

        // A round with nothing in it yet is given the default batch, if there is
        // one that applies (D30). One that has begun under the older flow keeps it.
        $untouched = ! $submission->requirements()->exists()
            && ! $submission->documents()->exists()
            && ! $submission->fields()->exists();

        if ($untouched && $this->batches->forPartner($submission)) {
            return $submission;
        }

        $this->captureRequirements->handle($submission);

        return $submission;
    }
}
