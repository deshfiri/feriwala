<?php

namespace App\Http\Resources\Mobile\Kyc;

use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The applicant's open KYC round and everything the form needs (§7).
 *
 * @property array{submission: KycSubmission, batch: array<string, mixed>|null, requirements: list<array<string, mixed>>, feedback: string|null} $resource
 */
class KycFormResource extends JsonResource
{
    /**
     * @return array{
     *     submission: array{id: string, status: string, status_label: string, status_tone: string, is_editable: bool, deadline_at: string|null},
     *     batch: array<string, mixed>|null,
     *     requirements: list<array<string, mixed>>,
     *     feedback: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var KycSubmission $submission */
        $submission = $this->resource['submission'];

        return [
            'submission' => [
                /** Public id of the round. */
                'id' => $submission->public_id,
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'status_tone' => $submission->status->tone(),
                /** False while the round is with a reviewer. */
                'is_editable' => $submission->status->isEditable(),
                'deadline_at' => $submission->deadline_at?->toIso8601String(),
            ],
            /**
             * The grouped form for a round on a batch version (D30): groups, options and
             * slots, with what each already holds. Evidence files carry a `document` public
             * id that opens through `GET /kyc/documents/{document}`. Null for a round on the
             * earlier flat list, which `requirements` carries instead.
             */
            'batch' => $this->resource['batch'],
            /**
             * The earlier flat list: each entry has `key` (send as `document_type`), `name`,
             * `instructions`, `is_required`, `requires_file`, `requires_value`, `value_label`,
             * `accepted_mime_types`, `max_size_kb`, `uploaded` and a masked `value_preview`.
             * Empty for a batch round.
             */
            'requirements' => $this->resource['requirements'],
            /** The reviewer's message to the applicant. Never the internal note. */
            'feedback' => $this->resource['feedback'],
        ];
    }
}
