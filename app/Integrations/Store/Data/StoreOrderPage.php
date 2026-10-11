<?php

namespace App\Integrations\Store\Data;

/**
 * One page of orders from a store, and where the next one starts (D29).
 *
 * Orders the driver could not read are reported, not dropped silently, and do
 * not stop the rest of the page from being imported.
 */
final readonly class StoreOrderPage
{
    /**
     * @param  list<StoreOrder>  $orders
     * @param  list<string>  $unreadable  why each unreadable order was skipped
     */
    public function __construct(
        public array $orders,
        public ?int $nextCursor,
        public int $highestId,
        public array $unreadable = [],
    ) {}
}
