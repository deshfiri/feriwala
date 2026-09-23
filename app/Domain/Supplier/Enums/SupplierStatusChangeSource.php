<?php

namespace App\Domain\Supplier\Enums;

/**
 * What moved a Supplier's status (D25, mirrors OrderStatusChangeSource).
 */
enum SupplierStatusChangeSource: string
{
    case Supplier = 'supplier';
    case Staff = 'staff';
    case System = 'system';
    case Scheduler = 'scheduler';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Staff => 'Staff',
            self::System => 'System',
            self::Scheduler => 'Scheduled check',
        };
    }
}
