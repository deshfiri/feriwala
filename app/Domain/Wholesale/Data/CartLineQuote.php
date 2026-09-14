<?php

namespace App\Domain\Wholesale\Data;

use App\Domain\Wholesale\Models\CartItem;
use App\Support\Money\Money;

/**
 * One cart line as the server prices it now (§14, P4-4, P4-5).
 *
 * Every figure here is worked out on the server at the moment the cart is read:
 * the unit price from the quantity pricing in force, the line total from that,
 * and what is available from central stock for this account. A line with any
 * problem is not purchasable and is left out of the subtotal until it is fixed
 * or removed — the cart never quietly charges for something it would refuse.
 *
 * `unitPrice` is null when the product is no longer available to the account:
 * a price for something the account may no longer see is not the account's
 * business.
 */
readonly class CartLineQuote
{
    public const UNAVAILABLE = 'unavailable';

    public const CHOOSE_VARIATION = 'choose_variation';

    public const VARIATION_UNAVAILABLE = 'variation_unavailable';

    public const BELOW_MINIMUM = 'below_minimum';

    public const ABOVE_MAXIMUM = 'above_maximum';

    public const INSUFFICIENT_STOCK = 'insufficient_stock';

    /**
     * @param  array<int, string>  $problems
     */
    public function __construct(
        public CartItem $item,
        public int $available,
        public ?Money $unitPrice,
        public ?Money $basePrice,
        public ?Money $lineTotal,
        public ?Money $unitPriceSeen,
        public bool $priceChanged,
        public array $problems,
    ) {}

    public function isPurchasable(): bool
    {
        return $this->problems === [];
    }
}
