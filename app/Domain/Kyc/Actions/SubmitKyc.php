<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Database\DatabaseManager;

/**
 * Submits a completed KYC round for review (§7.3).
 *
 * Completeness is checked against the document types that actually apply to this
 * applicant — their country and package (§7.2) — rather than against every type
 * in the system. Asking a Bangladeshi sole trader for a trade licence that only
 * applies to the Enterprise package would block them for no reason.
 */
class SubmitKyc
{
    public function __construct(
        protected ChangeAccountStatus $changeAccountStatus,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws KycIncomplete
     */
    public function handle(KycSubmission $submission, ?string $packageKey = null): KycSubmission
    {
        $user = $submission->user;

        $missing = $this->missingRequirements($submission, $packageKey, $user?->country);

        if ($missing !== []) {
            throw KycIncomplete::missing($missing);
        }

        return $this->database->transaction(function () use ($submission, $user) {
            $submission->transitionTo(KycStatus::Submitted);
            $submission->submitted_at = now();
            $submission->save();

            // Move the account along only when it is actually waiting on KYC.
            // Someone re-submitting after a correction is already further on.
            if ($user !== null && $user->canTransitionTo(AccountStatus::KycSubmitted)) {
                $this->changeAccountStatus->handle($user, new AccountStatusChange(
                    to: AccountStatus::KycSubmitted,
                    reason: 'KYC documents submitted for review.',
                ));
            }

            return $submission;
        });
    }

    /**
     * Names of required items this submission still lacks.
     *
     * @return array<int, string>
     */
    public function missingRequirements(
        KycSubmission $submission,
        ?string $packageKey,
        ?string $country,
    ): array {
        $types = KycDocumentType::query()->active()->with('scopes')->get();

        $documentTypeIds = $submission->documents()->pluck('kyc_document_type_id')->all();
        $fieldTypeIds = $submission->fields()
            ->whereNotNull('value')
            ->pluck('kyc_document_type_id')
            ->all();

        $missing = [];

        foreach ($types as $type) {
            if (! $type->is_required || ! $type->appliesTo($packageKey, $country)) {
                continue;
            }

            if ($type->requires_file && ! in_array($type->id, $documentTypeIds, true)) {
                $missing[] = $type->name;

                continue;
            }

            if ($type->requires_value && ! in_array($type->id, $fieldTypeIds, true)) {
                $missing[] = $type->name;
            }
        }

        return $missing;
    }
}
