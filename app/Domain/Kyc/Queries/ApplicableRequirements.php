<?php

namespace App\Domain\Kyc\Queries;

use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The KYC items one applicant must provide (§7.2).
 *
 * Scoped to their country and package, so nobody is asked for a trade licence
 * that only applies elsewhere. Optional types are included but flagged — a user
 * should be able to see what else they *may* supply, not just what blocks them.
 */
class ApplicableRequirements
{
    /**
     * @return Collection<int, KycDocumentType>
     */
    public function forUser(User $user, ?string $packageKey = null): Collection
    {
        return KycDocumentType::query()
            ->active()
            ->with('scopes')
            ->get()
            ->filter(fn (KycDocumentType $type) => $type->appliesTo($packageKey, $user->country))
            ->values();
    }

    /**
     * What the applicant sees on the form, with anything already supplied.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forForm(
        User $user,
        ?KycSubmission $submission = null,
        ?string $packageKey = null,
    ): array {
        $types = $this->forUser($user, $packageKey);

        $documents = $submission?->documents()->get()->keyBy('kyc_document_type_id')
            ?? collect();

        $fields = $submission?->fields()->get()->keyBy('kyc_document_type_id')
            ?? collect();

        return $types->map(function (KycDocumentType $type) use ($documents, $fields) {
            $document = $documents->get($type->id);
            $field = $fields->get($type->id);

            return [
                'id' => $type->public_id,
                'key' => $type->key,
                'name' => $type->name,
                'instructions' => $type->instructions,
                'is_required' => $type->is_required,
                'requires_file' => $type->requires_file,
                'requires_value' => $type->requires_value,
                'value_label' => $type->value_label,
                'accepted_mime_types' => $type->accepted_mime_types,
                'max_size_kb' => $type->max_size_kb,

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
}
