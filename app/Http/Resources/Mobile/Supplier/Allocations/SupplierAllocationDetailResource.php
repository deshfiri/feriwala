<?php

namespace App\Http\Resources\Mobile\Supplier\Allocations;

use App\Domain\Order\Models\OrderItem;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;

/**
 * One allocated order line in full: the summary plus what has been reversed on
 * its payable.
 *
 * @mixin OrderItem
 */
class SupplierAllocationDetailResource extends SupplierAllocationResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $payable = $this->supplierPayable;

        if ($data['payable'] !== null && $payable !== null) {
            $data['payable']['reversals'] = $payable->reversals->map(fn ($reversal) => [
                'quantity' => $reversal->quantity,
                'amount' => new MoneyResource($reversal->amount),
                'reason' => $reversal->reason,
                'created_at' => $reversal->created_at->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }
}
