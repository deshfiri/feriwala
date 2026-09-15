<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * The stock held for a paid order is missing, does not match its line, or has
 * already ended (§19.1, P4-10).
 *
 * Never shown to the buyer. It sends a settled order to review instead of to
 * paid, with this message kept for staff.
 */
class OrderStockUnconfirmable extends RuntimeException {}
