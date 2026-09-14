<?php

namespace App\Domain\Wholesale\Data;

use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * A whole cart as the server prices it now (§14, P4-5).
 *
 * The subtotal counts purchasable lines only. A cart with a problem on any line,
 * or a price that has changed since the person last saw it, cannot go on to
 * checkout until that is dealt with.
 */
readonly class CartQuote
{
    /**
     * @param  array<int, CartLineQuote>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
    ) {}

    public static function empty(): self
    {
        return new self([], Money::zero(Currency::BDT));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasProblems(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isPurchasable()) {
                return true;
            }
        }

        return false;
    }

    public function hasPriceChanges(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->priceChanged) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this cart may go on to checkout as it stands.
     */
    public function isReadyForCheckout(): bool
    {
        return ! $this->isEmpty() && ! $this->hasProblems() && ! $this->hasPriceChanges();
    }
}
