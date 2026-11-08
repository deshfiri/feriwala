<?php

namespace App\Http\Resources\Mobile\Supplier\Withdrawals;

use App\Domain\Supplier\Models\SupplierWithdrawal;
use Illuminate\Http\Request;

/**
 * One withdrawal in full: the summary, where it is paid, and how it got there.
 *
 * @mixin SupplierWithdrawal
 */
class SupplierWithdrawalDetailResource extends SupplierWithdrawalResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = parent::toArray($request);
        $snapshot = $this->payout_snapshot;

        return [
            ...$summary,
            /** The frozen payout details: masked numbers only, and no internal identifier. */
            'payout' => [
                ...$summary['payout'],
                'type' => $snapshot['type'] ?? null,
                'bank_name' => $snapshot['bank_name'] ?? null,
                'branch_name' => $snapshot['branch_name'] ?? null,
                'district' => $snapshot['district'] ?? null,
                'routing_number' => $snapshot['routing_number'] ?? null,
            ],
            /** Why it failed or was reversed, when it did. */
            'failure_reason' => $this->failure_reason,
            /** The payment reference once it has been paid. */
            'external_reference' => $this->external_reference,
            'history' => $this->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'reason' => $change->reason,
                'changed_at' => $change->changed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
