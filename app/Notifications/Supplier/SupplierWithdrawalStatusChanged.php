<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Models\SupplierWithdrawal;

class SupplierWithdrawalStatusChanged extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierWithdrawal $withdrawal,
        ?string $note = null,
    ) {
        parent::__construct($note);
    }

    protected function eventKey(): string
    {
        return 'supplier.withdrawal_'.$this->withdrawal->status->value;
    }

    protected function extra(): array
    {
        return [
            'withdrawal_reference' => $this->withdrawal->reference,
            'withdrawal_id' => $this->withdrawal->public_id,
            'status' => $this->withdrawal->status->value,
            'amount' => $this->withdrawal->amount->jsonSerialize(),
        ];
    }
}
