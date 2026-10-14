<?php

namespace App\Domain\Account\Queries;

use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;

/**
 * A person's own latest request to have their account deleted, as they see it.
 *
 * Self-scoped by construction: it takes the signed-in person and nothing else, so
 * there is no identifier to tamper with. It carries the reason they gave and, for
 * a refused one, the note staff wrote for them — never the reviewer's identity.
 */
class OwnDeletionRequest
{
    /**
     * @return array{id: string, status: string, reason: string, requested_at: string, decision_note: string|null}|null
     */
    public function for(User|Supplier $subject): ?array
    {
        $request = AccountDeletionRequest::query()
            ->where($subject instanceof Supplier ? 'supplier_id' : 'user_id', $subject->getKey())
            ->whereIn('status', [DeletionRequestStatus::Pending->value, DeletionRequestStatus::Rejected->value])
            ->latest('id')
            ->first();

        if ($request === null) {
            return null;
        }

        return [
            'id' => $request->public_id,
            'status' => $request->status->value,
            'reason' => $request->reason,
            'requested_at' => $request->requested_at->toIso8601String(),
            'decision_note' => $request->status === DeletionRequestStatus::Rejected ? $request->decision_note : null,
        ];
    }
}
