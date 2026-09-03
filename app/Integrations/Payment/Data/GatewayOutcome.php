<?php

namespace App\Integrations\Payment\Data;

use App\Domain\Billing\Enums\PaymentStatus;

/**
 * The outcomes every gateway is mapped onto.
 *
 * Providers use their own vocabularies — VALID, SUCCESS, COMPLETED, 2. Mapping
 * them to a closed set here means the payment flow never has to know one
 * gateway's spelling from another's.
 */
enum GatewayOutcome: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Paid => PaymentStatus::Paid,
            self::Pending => PaymentStatus::Pending,
            self::Failed => PaymentStatus::Failed,
            self::Cancelled => PaymentStatus::Cancelled,
        };
    }

    /**
     * Whether this outcome may release value — activate an account, credit a
     * wallet, commit stock.
     */
    public function releasesValue(): bool
    {
        return $this === self::Paid;
    }
}
