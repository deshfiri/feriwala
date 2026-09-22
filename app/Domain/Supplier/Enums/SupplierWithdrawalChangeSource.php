<?php

namespace App\Domain\Supplier\Enums;

/**
 * What moved a Supplier withdrawal's status (D25, mirrors PayableChangeSource).
 */
enum SupplierWithdrawalChangeSource: string
{
    case Supplier = 'supplier';
    case Staff = 'staff';
    case System = 'system';
    case Scheduler = 'scheduler';
    case PaymentGateway = 'payment_gateway';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Staff => 'Staff',
            self::System => 'System',
            self::Scheduler => 'Scheduled check',
            self::PaymentGateway => 'Payment gateway',
        };
    }
}
