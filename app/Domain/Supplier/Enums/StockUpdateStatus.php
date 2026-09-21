<?php

namespace App\Domain\Supplier\Enums;

/**
 * A Supplier-submitted availability change, waiting on Admin authorization
 * before it takes effect (D25, P13-17).
 */
enum StockUpdateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
