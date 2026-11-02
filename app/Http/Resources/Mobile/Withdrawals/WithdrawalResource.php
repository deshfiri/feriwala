<?php

namespace App\Http\Resources\Mobile\Withdrawals;

use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the account's withdrawal requests (§27), as the list shows it.
 *
 * @mixin AccountWithdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'reference' => $this->reference,
            'amount' => new MoneyResource($this->amount),
            /** One of `statuses[].value`. */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            /** Where it will be paid, frozen at request time and never the full account number. */
            'payout' => [
                'type_label' => $this->payout_snapshot['type_label'] ?? null,
                'label' => $this->payout_snapshot['label'] ?? null,
                'masked_number' => $this->payout_snapshot['masked_number'] ?? null,
            ],
            'requested_at' => $this->requested_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
