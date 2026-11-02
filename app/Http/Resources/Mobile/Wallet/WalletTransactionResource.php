<?php

namespace App\Http\Resources\Mobile\Wallet;

use App\Domain\Wallet\Models\WalletTransaction;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One wallet movement as the account holder sees it.
 *
 * Never carries the internal note: the holder is who a staff note is *about*
 * (§23.2, §7.3). A debit is a positive amount with direction `debit`, so the
 * app reads `direction`, never the sign.
 *
 * @mixin WalletTransaction
 */
class WalletTransactionResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->ledgerEntries->last();

        return [
            /** Public identifier (ULID). */
            'id' => $this->public_id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            /** `debit` or `credit`. */
            'direction' => $this->direction->value,
            'description' => $this->description,
            'source' => $this->source,
            'amount' => new MoneyResource($this->amount),
            /** Null where nothing moved: a reservation is not a debit of zero. */
            'debit' => MoneyResource::nullable($entry?->debit),
            'credit' => MoneyResource::nullable($entry?->credit),
            /** The balance this movement left behind; absent for a claim. */
            'balance_after' => MoneyResource::nullable($entry?->balance_after),
            'entry_reference' => $entry?->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'is_correction' => $this->type->isCorrection(),
            'reason' => $this->reason,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
