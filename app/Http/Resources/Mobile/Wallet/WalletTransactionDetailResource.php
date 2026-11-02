<?php

namespace App\Http\Resources\Mobile\Wallet;

use App\Domain\Wallet\Models\WalletTransaction;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;

/**
 * One movement in full: the summary plus the ledger entries it produced.
 *
 * @mixin WalletTransaction
 */
class WalletTransactionDetailResource extends WalletTransactionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'payment_reference' => $this->payment?->reference,
            'currency' => $this->currency_code,
            'entries' => $this->ledgerEntries->map(fn ($entry) => [
                'reference' => $entry->reference,
                /** Which kind of money this entry moved. */
                'balance_type' => $entry->balance_type->value,
                'balance_type_label' => $entry->balance_type->label(),
                'debit' => new MoneyResource($entry->debit),
                'credit' => new MoneyResource($entry->credit),
                'balance_before' => new MoneyResource($entry->balance_before),
                'balance_after' => new MoneyResource($entry->balance_after),
                'at' => $entry->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
