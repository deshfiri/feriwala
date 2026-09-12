<?php

namespace App\Integrations\Payment\Data;

/**
 * The three things a provider can say about a refund.
 *
 * Kept separate from {@see GatewayOutcome} because they are not the same
 * question. A payment that is "pending" has not arrived; a refund that is
 * pending has been **accepted and not yet settled**, which is a different fact
 * with a different consequence — one holds value back, the other has already
 * promised it away.
 */
enum GatewayRefundOutcome: string
{
    case Succeeded = 'succeeded';
    case Pending = 'pending';
    case Failed = 'failed';

    /**
     * Whether the money has actually gone back.
     *
     * The only outcome that may reverse anything in our own records.
     */
    public function isSettled(): bool
    {
        return $this === self::Succeeded;
    }

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Refunded',
            self::Pending => 'Refund in progress',
            self::Failed => 'Refund failed',
        };
    }
}
