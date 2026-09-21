<?php

namespace App\Notifications\Supplier;

class SupplierKycSubmitted extends SupplierLifecycleNotification
{
    protected function eventKey(): string
    {
        return 'supplier.kyc_submitted';
    }
}
