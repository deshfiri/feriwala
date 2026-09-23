<?php

namespace App\Notifications\Supplier;

class SupplierKycCorrectionRequested extends SupplierLifecycleNotification
{
    protected function eventKey(): string
    {
        return 'supplier.kyc_correction_requested';
    }
}
