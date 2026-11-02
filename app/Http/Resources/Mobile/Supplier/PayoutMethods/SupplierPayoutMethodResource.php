<?php

namespace App\Http\Resources\Mobile\Supplier\PayoutMethods;

use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Models\PayoutMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a Supplier's own payout methods. The account number is only ever masked.
 *
 * @mixin PayoutMethod
 */
class SupplierPayoutMethodResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            /** `bank_account`, `bkash` or `nagad`. */
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            /** The account number with all but the last digits hidden. */
            'masked_number' => $this->maskedNumber(),
            'bank_name' => $this->bank?->name,
            'branch_name' => $this->branch?->name,
            'is_default' => $this->is_default,
            'is_active' => $this->status === PayoutMethodStatus::Active,
            'status_label' => $this->status->label(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}