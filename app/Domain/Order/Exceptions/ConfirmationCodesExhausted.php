<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * A cash-on-delivery order has been sent every confirmation code it may be
 * (§6.2, P6-10).
 *
 * The order is not cancelled for it: its window still runs, and staff can see
 * the codes are spent. It simply gets no more.
 */
class ConfirmationCodesExhausted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This order has been sent every confirmation code it may be.');
    }
}
