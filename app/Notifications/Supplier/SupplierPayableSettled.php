<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Money;

class SupplierPayableSettled extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierPayable $payable,
        protected readonly Money $amount,
    ) {
        parent::__construct();
    }

    protected function eventKey(): string
    {
        return 'supplier.payable_settled';
    }

    protected function extra(): array
    {
        return [
            'payable_reference' => $this->payable->reference,
            'payable_id' => $this->payable->public_id,
            'settlement_reference' => $this->payable->settlement_reference,
            'amount' => $this->amount->jsonSerialize(),
        ];
    }
}
