<?php

namespace App\Domain\Kyc\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycReview;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use Illuminate\Support\Collection;

/**
 * An applicant's own KYC history (§7.3, P1-27).
 *
 * The whole point of this class is what it leaves out. A KYC round carries
 * three kinds of writing about the applicant, and only one of them is theirs to
 * read:
 *
 *   - `user_visible_feedback` — written **to** them. Included.
 *   - `reason` and `internal_note` — written **about** them, for colleagues and
 *     auditors. Never included.
 *   - `request_reason` on a requested update — likewise internal; the applicant
 *     gets `request_instructions`.
 *
 * Assembling the payload here rather than in a controller means there is one
 * place to audit that, and one place a test can hold. A reviewer's private note
 * reaching the person it is about is not a display bug — it is a disclosure.
 *
 * Reviewer identity is omitted for the same reason: an applicant needs to know
 * what was decided, not which member of staff to argue with.
 */
class ApplicantKycHistory
{
    /**
     * Every round this account has had, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forAccount(BusinessAccount $account): array
    {
        $submissions = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->with(['requirements', 'documents', 'fields', 'reviews'])
            ->orderByDesc('round')
            ->get();

        return $submissions->map(fn (KycSubmission $submission) => $this->round($submission))->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function round(KycSubmission $submission): array
    {
        $documents = $submission->documents->keyBy('kyc_document_type_id');
        $fields = $submission->fields->keyBy('kyc_document_type_id');

        return [
            'id' => $submission->public_id,
            'round' => $submission->round,

            'status' => $submission->status->value,
            'status_label' => $submission->status->label(),
            'status_tone' => $submission->status->tone(),
            'is_editable' => $submission->status->isEditable(),

            'opened_at' => $submission->created_at?->toIso8601String(),
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),

            'deadline_at' => $submission->deadline_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining($submission),
            'is_overdue' => $submission->isOverdue(),

            /*
             * A round an administrator asked for (§7.2). The applicant is told
             * that it was requested and what to do — never why we asked, which
             * is `request_reason` and stays internal.
             */
            'was_requested' => $submission->wasRequested(),
            'instructions' => $submission->request_instructions,

            'requirements' => $submission->requirements
                ->map(fn (KycSubmissionRequirement $requirement) => [
                    'key' => $requirement->key,
                    'name' => $requirement->name,
                    'instructions' => $requirement->instructions,
                    'is_required' => $requirement->is_required,
                    'status' => $this->requirementStatus($requirement, $documents, $fields),
                ])->values()->all(),

            // Only what was written to them, newest first.
            'feedback' => $submission->reviews
                ->map(fn (KycReview $review) => [
                    'outcome' => $review->to_status,
                    'feedback' => $review->user_visible_feedback,
                    'at' => $review->created_at->toIso8601String(),
                ])
                ->filter(fn (array $entry) => filled($entry['feedback']))
                ->values()
                ->all(),
        ];
    }

    /**
     * Whether one item has been supplied — never where the file is stored.
     *
     * §7.5 keeps the disk, path and checksum out of every representation; the
     * applicant needs to know an upload landed, not how to reach it.
     *
     * @param  Collection<int, KycDocument>  $documents
     * @param  Collection<int, mixed>  $fields
     */
    protected function requirementStatus(
        KycSubmissionRequirement $requirement,
        $documents,
        $fields,
    ): string {
        $document = $documents->get($requirement->kyc_document_type_id);
        $field = $fields->get($requirement->kyc_document_type_id);

        $needsFile = $requirement->requires_file;
        $needsValue = $requirement->requires_value;

        $hasFile = $document !== null;
        $hasValue = $field !== null && filled($field->value);

        if (($needsFile && ! $hasFile) || ($needsValue && ! $hasValue)) {
            return $requirement->is_required ? 'outstanding' : 'not_supplied';
        }

        return 'supplied';
    }

    protected function daysRemaining(KycSubmission $submission): ?int
    {
        if ($submission->deadline_at === null) {
            return null;
        }

        // Floor rather than round: "1 day left" must not appear for something
        // due in twenty minutes. Negative once the deadline has passed, which
        // the caller reads alongside `is_overdue`.
        return (int) floor(
            now()->diffInHours($submission->deadline_at, absolute: false) / 24
        );
    }
}
