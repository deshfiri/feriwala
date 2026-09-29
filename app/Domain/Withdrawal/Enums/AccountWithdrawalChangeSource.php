<?php

namespace App\Domain\Withdrawal\Enums;

/**
 * What moved an account withdrawal's status (mirrors SupplierWithdrawalChangeSource).
 */
enum AccountWithdrawalChangeSource: string
{
    case Account = 'account';
    case Staff = 'staff';
    case System = 'system';
    case Scheduler = 'scheduler';
    case PaymentGateway = 'payment_gateway';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account',
            self::Staff => 'Staff',
            self::System => 'System',
            self::Scheduler => 'Scheduled check',
            self::PaymentGateway => 'Payment gateway',
        };
    }
}
