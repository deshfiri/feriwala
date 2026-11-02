<?php

namespace App\Http\Resources\Mobile\Withdrawals;

use App\Domain\Withdrawal\Models\AccountWithdrawal;
use Illuminate\Http\Request;

/**
 * One withdrawal in full: the summary, where it is paid, and how it got there.
 *
 * Shows what the web page shows the same account and no more: never the staff
 * decision note nor who decided or processed it.
 *
 * @mixin AccountWithdrawal
 */
class WithdrawalDetailResource extends WithdrawalResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->payout_snapshot;

        return [
            ...parent::toArray($request),
            'payout' => [
                ...parent::toArray($request)['payout'],
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
