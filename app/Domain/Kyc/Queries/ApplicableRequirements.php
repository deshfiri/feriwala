<?php

namespace App\Domain\Kyc\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use Illuminate\Support\Collection;

/**
 * The KYC items one applicant must provide (§7.2).
 *
 * Reads a round's **snapshot** wherever there is one. What a round asks for is
 * fixed when it opens, so an administrator editing a document type today cannot
 * change what a submission made last week was judged on — a reviewer must not
 * read "passport required" beside a round completed when it was optional.
 *
 * Only where no round exists — a preview of what an account *would* be asked
 * for — does it fall back to resolving live configuration.
 *
 * Scoped to country and package, so nobody is asked for a trade licence that
 * only applies elsewhere. Optional items are included but flagged: a user should
 * see what else they may supply, not only what blocks them.
 */
class ApplicableRequirements
{
    /**
     * Live resolution, for an account with no round open yet.
     *
     * @return Collection<int, KycDocumentType>
     */
    public function forUser(BusinessAccount $account, ?string $packageKey = null): Collection
    {
        return KycDocumentType::query()
            ->forAccounts()
            ->active()
            ->with(['scopes', 'batch.scopes'])
            ->get()
            ->filter(fn (KycDocumentType $type) => $type->batchAppliesTo($packageKey, $account->owner?->country)
                && $type->appliesTo($packageKey, $account->owner?->country))
            ->sortBy(fn (KycDocumentType $type) => [$type->batchSortOrder(), $type->sort_order, $type->id])
            ->values();
    }

    /**
     * What the applicant sees on the form, with anything already supplied.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forForm(
        BusinessAccount $account,
        ?KycSubmission $submission = null,
        ?string $packageKey = null,
    ): array {
        // A round on a batch version has its own grouped form; the flat list
        // below — including its "fall back to live configuration" — is only for
        // rounds opened before batches were versioned (D30).
        if ($submission?->kyc_batch_version_id !== null) {
            return [];
        }

        $requirements = $submission?->requirements()->get() ?? collect();

        if ($requirements->isEmpty()) {
            return $this->fromLiveConfiguration($account, $submission, $packageKey);
        }

        $documents = $submission?->documents()->get()->keyBy('kyc_document_type_id') ?? collect();
        $fields = $submission?->fields()->get()->keyBy('kyc_document_type_id') ?? collect();

        // values(): sorting keeps the old keys, and a list with gaps in its keys
        // reaches the browser as an object, not an array.
        return $requirements->sortBy(fn (KycSubmissionRequirement $requirement) => [$requirement->batch_sort_order, $requirement->sort_order, $requirement->id])->values()->map(function (KycSubmissionRequirement $requirement) use ($documents, $fields) {
            $document = $documents->get($requirement->kyc_document_type_id);
            $field = $fields->get($requirement->kyc_document_type_id);

            return [
                'batch' => $this->batchOf($requirement->batch_key, $requirement->batch_name, $requirement->batch_description),
                'key' => $requirement->key,
                'name' => $requirement->name,
                'instructions' => $requirement->instructions,
                'is_required' => $requirement->is_required,
                'requires_file' => $requirement->requires_file,
                'requires_value' => $requirement->requires_value,
                'value_label' => $requirement->value_label,
                'accepted_mime_types' => $requirement->accepted_mime_types,
                'max_size_kb' => $requirement->max_size_kb,

                // Enough to show "already uploaded" without exposing the path
                // or letting the browser fetch the file (§7.5).
                'uploaded' => $document === null ? null : [
                    'name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                ],

                // The stored value is masked. A reviewer sees the full number
                // through the reviewer screens; the form does not need to echo
                // it back in full (§36 masking).
                'value_preview' => $field?->masked(),
            ];
        })->all();
    }

    /**
     * The fallback for a round with no snapshot — one opened before snapshots
     * existed, or a preview with no round at all.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fromLiveConfiguration(
        BusinessAccount $account,
        ?KycSubmission $submission,
        ?string $packageKey,
    ): array {
        $types = $this->forUser($account, $packageKey);
        $country = $account->owner?->country;

        $documents = $submission?->documents()->get()->keyBy('kyc_document_type_id') ?? collect();
        $fields = $submission?->fields()->get()->keyBy('kyc_document_type_id') ?? collect();

        return $types->map(function (KycDocumentType $type) use ($documents, $fields, $packageKey, $country) {
            $document = $documents->get($type->id);
            $field = $fields->get($type->id);

            return [
                'batch' => $this->batchOf($type->batch?->key, $type->batch?->name, $type->batch?->description),
                'key' => $type->key,
                'name' => $type->name,
                'instructions' => $type->instructions,
                'is_required' => $type->isRequiredFor($packageKey, $country),
                'requires_file' => $type->requires_file,
                'requires_value' => $type->requires_value,
                'value_label' => $type->value_label,
                'accepted_mime_types' => $type->accepted_mime_types,
                'max_size_kb' => $type->max_size_kb,
                'uploaded' => $document === null ? null : [
                    'name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                ],
                'value_preview' => $field?->masked(),
            ];
        })->all();
    }

    /**
     * The batch a requirement is shown under, or null when it has none (a
     * criterion made outside the admin screen, or one asked for before batches
     * existed). The form draws no heading for those.
     *
     * @return array{key: string, name: string, description: string|null}|null
     */
    protected function batchOf(?string $key, ?string $name, ?string $description): ?array
    {
        return $key === null || $name === null ? null : ['key' => $key, 'name' => $name, 'description' => $description];
    }
}
