<?php

namespace App\Http\Controllers\Api\Mobile\V1\Kyc\Concerns;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Batch\KycRoundPresenter;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Queries\ApplicableRequirements;
use App\Http\Resources\Mobile\Kyc\KycFormResource;

/**
 * The KYC form as the app reads it, for the screen and for every change that
 * answers with the refreshed form.
 */
trait PresentsKycForm
{
    protected function kycForm(BusinessAccount $account, KycSubmission $submission): KycFormResource
    {
        return new KycFormResource([
            'submission' => $submission,
            'batch' => app(KycRoundPresenter::class)->present(
                new PartnerKycRound($submission, app(KycDocumentStore::class)),
            ),
            'requirements' => app(ApplicableRequirements::class)->forForm($account, $submission),
            'feedback' => $submission->reviews()->first()?->user_visible_feedback,
        ]);
    }
}
