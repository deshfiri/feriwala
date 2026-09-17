<?php

namespace App\Domain\Website\Enums;

/**
 * Where one webhook delivery stands (contract §7.4).
 *
 * The contract's four states. `Failed` is the dead-letter state: the retries
 * are spent, the delivery is in the failed-sync queue, and only a person
 * sends it again.
 */
enum WebhookDeliveryState: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Retrying = 'retrying';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Delivered => 'Delivered',
            self::Retrying => 'Retrying',
            self::Failed => 'Failed',
        };
    }
}
