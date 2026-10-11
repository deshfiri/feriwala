<?php

namespace App\Integrations\Store\Data;

use App\Support\Money\Money;

/**
 * One line of an order, in the shape every store driver hands back (D29).
 */
final readonly class StoreOrderLine
{
    public function __construct(
        public string $name,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        public ?string $sku = null,
        public ?string $externalProductId = null,
        public ?string $variantLabel = null,
    ) {}
}
