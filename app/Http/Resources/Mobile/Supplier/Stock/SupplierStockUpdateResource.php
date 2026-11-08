<?php

namespace App\Http\Resources\Mobile\Supplier\Stock;

use App\Domain\Supplier\Models\SupplierStockUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An availability update the Supplier submitted. Nothing moves until staff
 * approve it.
 *
 * @mixin SupplierStockUpdate
 */
class SupplierStockUpdateResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Public identifier (ULID). */
            'id' => $this->public_id,
            'requested_quantity' => $this->requested_quantity,
            /** `pending`, or the staff decision. */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
