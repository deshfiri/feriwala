<?php

namespace App\Http\Resources\Mobile\Supplier\Fulfilment;

use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use Illuminate\Http\Request;

/**
 * A fulfilment commitment in full: the summary and its status history.
 *
 * @mixin SupplierFulfilmentCommitment
 */
class SupplierFulfilmentDetailResource extends SupplierFulfilmentResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'history' => $this->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'changed_by' => $change->source->label(),
                'changed_at' => $change->changed_at->toIso8601String(),
                'reason' => $change->reason,
            ])->values()->all(),
        ];
    }
}
