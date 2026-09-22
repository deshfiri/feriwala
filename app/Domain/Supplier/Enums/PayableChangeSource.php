<?php

namespace App\Domain\Supplier\Enums;

/**
 * What moved a Supplier payable's status (D25, mirrors OrderStatusChangeSource).
 */
enum PayableChangeSource: string
{
    case System = 'system';
    case Staff = 'staff';
    case Scheduler = 'scheduler';
    case PaymentGateway = 'payment_gateway';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Staff => 'Staff',
            self::Scheduler => 'Scheduled check',
            self::PaymentGateway => 'Payment gateway',
        };
    }
}
