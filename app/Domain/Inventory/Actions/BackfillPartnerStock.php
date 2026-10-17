<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;

/**
 * Give every Partner the units their earlier paid wholesale purchases bought
 * (D34).
 *
 * Nothing recorded those units before: central stock saw them leave and no table
 * said whose they became, so there is no earlier figure to reconcile with or to
 * double count. Each order line is credited once, keyed by the line itself —
 * the same key {@see ReceivePartnerPurchase} uses at payment — so running this
 * twice, or over an order that has since been credited at payment, adds nothing.
 */
class BackfillPartnerStock
{
    public function __construct(
        protected ReceivePartnerPurchase $purchases,
    ) {}

    /**
     * @return array{orders: int, units: int} the paid orders looked at, and the units credited now
     */
    public function handle(): array
    {
        $orders = 0;
        $units = 0;

        Order::query()
            ->where('source', OrderSource::ErpWholesale)
            ->whereNotNull('paid_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$orders, &$units) {
                foreach ($chunk as $order) {
                    $orders++;
                    $units += $this->purchases->handle($order);
                }
            });

        return ['orders' => $orders, 'units' => $units];
    }
}
