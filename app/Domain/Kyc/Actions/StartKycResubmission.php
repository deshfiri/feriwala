<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Opens a fresh KYC round after corrections were requested (§7.3).
 *
 * A new round, never an edit of the reviewed one. The previous round is what the
 * reviewer actually saw and decided on; letting the applicant change it
 * afterwards would make the review record describe documents that no longer
 * exist, and would hide what changed between attempts.
 */
class StartKycResubmission
{
    public function __construct(
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
                throw new RuntimeException('This account has no KYC submission to resubmit.');
            }

            if ($latest->status->isEditable()) {
                // Already has an open draft — hand that back rather than
                // creating a second one they would have to choose between.
                return $latest;
            }

            if (! in_array($latest->status, [
                KycStatus::ResubmissionRequired,
                KycStatus::Rejected,
            ], true)) {
                throw new RuntimeException(
                    "A round in [{$latest->status->value}] cannot be resubmitted."
                );
            }

            return KycSubmission::create([
                'business_account_id' => $account->id,
                'status' => KycStatus::Draft,
                'round' => $latest->round + 1,
                'deadline_at' => $latest->deadline_at,
            ]);
        });
    }
}
