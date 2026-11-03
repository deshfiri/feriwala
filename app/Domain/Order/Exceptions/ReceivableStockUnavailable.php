<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * The stock held for a Partner-gateway order lapsed and none could be set aside
 * again, so Banij's receivable must not be debited (D36).
 *
 * Thrown inside the collection transaction so that everything done so far for
 * the order — reservations made for earlier lines included — rolls back with it.
 * The message is for staff and names a line, never a customer.
 */
class ReceivableStockUnavailable extends RuntimeException
{
    public static function forLine(int $lineNumber, string $why): self
    {
        return new self(sprintf('Line %d: %s', $lineNumber, $why));
    }
}
