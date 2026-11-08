<?php

namespace App\Http\Resources\Mobile\Supplier\Wallet;

use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the Supplier's own statement. Never an internal note or an
 * idempotency key. A debit is a positive amount with `is_credit` false, so the
 * app reads that flag, never the sign.
 *
 * @mixin SupplierLedgerEntry
 */
class SupplierLedgerEntryResource extends JsonResource
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
            'reference' => $this->reference,
            /** One of `types[].value`. */
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'balance_type' => $this->balance_type->value,
            'balance_type_label' => $this->balance_type->label(),
            'is_credit' => $this->isCredit(),
            'amount' => new MoneyResource($this->amount()),
            'balance_after' => new MoneyResource($this->balance_after),
            'description' => $this->description,
            'related_payable_id' => $this->payable?->public_id,
            'related_payable_reference' => $this->payable?->reference,
            'related_withdrawal_id' => $this->withdrawal?->public_id,
            'related_withdrawal_reference' => $this->withdrawal?->reference,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
