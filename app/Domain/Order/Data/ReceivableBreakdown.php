<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Enums\ReceivableComponent;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * Every component of an order's platform receivable, and their exact sum (D36).
 */
readonly class ReceivableBreakdown
{
    /**
     * @param  list<ReceivableLine>  $lines
     */
    public function __construct(
        public Currency $currency,
        public array $lines,
    ) {}

    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            fn (Money $sum, ReceivableLine $line) => $sum->plus($line->amount),
            Money::zero($this->currency),
        );
    }

    /**
     * What one kind of component comes to across the order.
     */
    public function totalOf(ReceivableComponent $component): Money
    {
        return array_reduce(
            array_filter($this->lines, fn (ReceivableLine $line) => $line->component === $component),
            fn (Money $sum, ReceivableLine $line) => $sum->plus($line->amount),
            Money::zero($this->currency),
        );
    }
}
