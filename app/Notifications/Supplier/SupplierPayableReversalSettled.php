<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Models\SupplierPayableReversal;
use App\Support\Money\Money;

/**
 * A previously settled payable was reversed and the wallet was debited for
 * it (D25, P13-25's settlement side). Sent only when the reversal actually
 * touched the wallet — a reversal of a payable that was never settled moves
 * no money and notifies through the existing payable-status notifications
 * instead.
 */
class SupplierPayableReversalSettled extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierPayableReversal $reversal,
        protected readonly Money $debited,
        protected readonly Money $recorded,
    ) {
        parent::__construct();
    }

    protected function eventKey(): string
    {
        return 'supplier.payable_reversal_settled';
    }

    protected function extra(): array
    {
        return [
            'reversal_id' => $this->reversal->public_id,
            'debited' => $this->debited->jsonSerialize(),
            'recovery_recorded' => $this->recorded->jsonSerialize(),
        ];
    }
}
