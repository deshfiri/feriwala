<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * An order line could not be allocated to the source that was chosen.
 *
 * Always carries a reason a member of staff can act on — the source ran out,
 * the Supplier went offline, someone else took the line a moment earlier. The
 * panel shows it and refreshes the candidates, so the answer to a refusal is
 * always "choose again from what is actually there" rather than a generic
 * failure.
 */
class AllocationRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
