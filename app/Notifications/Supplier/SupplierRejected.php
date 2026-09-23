<?php

namespace App\Notifications\Supplier;

class SupplierRejected extends SupplierLifecycleNotification
{
    protected function eventKey(): string
    {
        return 'supplier.rejected';
    }
}
