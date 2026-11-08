<?php

namespace App\Http\Resources\Mobile\Supplier\Payables;

use App\Domain\Supplier\Models\SupplierPayable;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;

/**
 * A payable in full: the summary, what has been reversed, and its history.
 *
 * @mixin SupplierPayable
 */
class SupplierPayableDetailResource extends SupplierPayableResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'reversals' => $this->reversals->map(fn ($reversal) => [
                'quantity' => $reversal->quantity,
                'amount' => new MoneyResource($reversal->amount),
                'reason' => $reversal->reason,
                /** Set only once the reversal clawed money back from the wallet. */
                'wallet_reference' => $reversal->settlement_reversal_reference,
                'created_at' => $reversal->created_at->toIso8601String(),
            ])->values()->all(),
            'history' => $this->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'reason' => $change->reason,
                'changed_at' => $change->changed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
