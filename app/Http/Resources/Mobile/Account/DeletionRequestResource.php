<?php

namespace App\Http\Resources\Mobile\Account;

use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person's own request to have their account deleted.
 *
 * Carries the reason they gave and, for a refused one, the note written for
 * them. Never the reviewer's identity.
 *
 * @mixin AccountDeletionRequest
 */
class DeletionRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            /** `pending`, `approved`, `rejected` or `withdrawn`. */
            'status' => $this->status->value,
            'reason' => $this->reason,
            'requested_at' => $this->requested_at->toIso8601String(),
            /** Staff's explanation; only present once the request was rejected. */
            'decision_note' => $this->status === DeletionRequestStatus::Rejected ? $this->decision_note : null,
        ];
    }
}
