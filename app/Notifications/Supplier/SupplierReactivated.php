<?php

namespace App\Notifications\Supplier;

class SupplierReactivated extends SupplierLifecycleNotification
{
    protected function eventKey(): string
    {
        return 'supplier.reactivated';
    }
}
