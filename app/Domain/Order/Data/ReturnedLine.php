<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Enums\ReturnDisposition;

/**
 * What arrived for one returned line, and what the person holding it decided
 * to do with it (§19.1, P6-12).
 *
 * The disposition is never defaulted: whether a returned item is fit to sell
 * again is judged with the item in hand, and a missing judgement is a request
 * that cannot be taken.
 */
final class ReturnedLine
{
    public function __construct(
        public readonly string $lineId,
        public readonly int $quantity,
        public readonly ReturnDisposition $disposition,
    ) {}
}
