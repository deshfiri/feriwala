<?php

namespace App\Domain\Order\Data;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Money;

/**
 * One replacement line of an exchange, as the ERP prices it (D35).
 *
 * `$replaces` is the line of the original order whose goods are coming back.
 * The replacement is a different line — never the original mutated into it —
 * with its own price, discount and tax, all fixed here from the server's own
 * data and written once.
 *
 * **Same product, no new sale.** A replacement of the same product, in any of
 * its variations, carries the unit price the customer already paid and is
 * wholly covered by the returned goods: its discount is its whole subtotal, so
 * it costs the customer nothing and creates no second sale, profit or payable.
 * **A different product** is priced from the Partner's own published price for
 * it today; the returned goods are a credit against it, and the difference —
 * either way — is worked out exactly on the quote, never silently absorbed.
 */
readonly class ExchangeLine
{
    public function __construct(
        public OrderItem $replaces,
        public Product $product,
        public ?ProductVariant $variant,
        public ?WebsiteProduct $selection,
        public string $sku,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        /** What of the subtotal the returned goods cover. */
        public Money $discount,
        /** Tax added on top of the price; nothing when the price already includes it. */
        public Money $tax,
        public Money $taxIncluded,
        public ?string $taxCode,
        public ?int $taxRateBasisPoints,
        public ?string $taxMode,
        /** Whether this is the same product as the line it replaces. */
        public bool $sameProduct,
        /** What the returned units were worth to the customer: the credit this line draws on. */
        public Money $credit,
    ) {}

    public function total(): Money
    {
        return $this->subtotal->minus($this->discount)->plus($this->tax);
    }

    /**
     * What this line adds to what the customer owes (positive) or is owed
     * (negative): the replacement's worth against the goods sent back. Zero for
     * the same product, by definition.
     */
    public function difference(): Money
    {
        if ($this->sameProduct) {
            return Money::zero($this->subtotal->currency);
        }

        return $this->subtotal->plus($this->tax)->minus($this->credit);
    }
}
