<?php

namespace App\Notifications\Supplier;

class SupplierApproved extends SupplierLifecycleNotification
{
    protected function eventKey(): string
    {
        return 'supplier.approved';
    }
}
