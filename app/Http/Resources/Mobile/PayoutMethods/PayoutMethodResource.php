<?php

namespace App\Http\Resources\Mobile\PayoutMethods;

use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Models\PayoutMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the account's payout methods. Never the account number: only its masked form.
 *
 * @mixin PayoutMethod
 */
class PayoutMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            /** One of `types[].value`. */
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            /** The last four digits behind bullets, e.g. `••••2222`. */
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
