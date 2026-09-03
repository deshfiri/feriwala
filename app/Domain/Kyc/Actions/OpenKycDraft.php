<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
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
        protected DatabaseManager $database,
    ) {}

    public function handle(User $user): KycSubmission
    {
        return $this->database->transaction(function () use ($user) {
            $latest = KycSubmission::query()
                ->where('user_id', $user->id)
                ->orderByDesc('round')
                ->lockForUpdate()
                ->first();

            if ($latest === null) {
                return KycSubmission::create([
                    'user_id' => $user->id,
                    'status' => KycStatus::Draft,
                    'round' => 1,
                ]);
            }

            if ($latest->status->isEditable()) {
                return $latest;
            }

            // Awaiting or holding a decision — the applicant should be looking
            // at it, not editing it.
            if ($latest->status->awaitsReview() || $latest->status === KycStatus::Approved) {
                return $latest;
            }

            return $this->startResubmission->handle($user);
        });
    }
}
