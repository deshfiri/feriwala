<?php

namespace App\Notifications\Supplier;

class SupplierSuspended extends SupplierLifecycleNotification
{
    protected function eventKey(): string
    {
        return 'supplier.suspended';
    }
}
