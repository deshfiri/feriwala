<?php

namespace App\Domain\Courier\Exceptions;

use RuntimeException;

/**
 * A shipping label Banij would not issue. `$reason` is the safe, stable code
 * recorded on the shipment; the message is fixed, translated text.
 */
class ShipmentLabelRefused extends RuntimeException
{
    /** Reasons that are a normal wait, not something a person must fix. */
    public const WAITING = ['waiting_for_courier'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(__("shipping_label.refused.{$reason}"));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function needsStaff(): bool
    {
        return ! in_array($this->reason, self::WAITING, true);
    }
}
