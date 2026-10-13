<?php

namespace App\Domain\Website\Actions;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild every website's best sellers from its last 30 days of sales
 * (P2 C3, D31 H5).
 *
 * **What counts.** A website order placed in the window that is either
 *
 *   - **paid online** — its payment went through a gateway (an online order's
 *     payment always names one) and the order has `paid_at`; or
 *   - **confirmed cash on delivery** — its payment names no gateway (cash on
 *     delivery never does) and its history holds the move to `confirmed` made
 *     by the storefront, which only {@see ConfirmCodOrder} writes. The order's
 *     current status alone is never taken as proof;
 *
 * and is not now cancelled, refund pending, partially refunded or refunded.
 *
 * **Net units.** Each line's quantity, less what came back on returns that
 * were received (`received` or `refunded`), never below zero. Returns are
 * summed per order line *before* the join, so a line with several returns is
 * reduced once by their total, and no order is multiplied by its returns.
 *
 * **Ranking** (read by the catalogue): net units descending, then distinct
 * orders that still carry a net unit descending, then the selection id. A
 * selection with no row here sold nothing in the window and ranks after every
 * one that did, in id order.
 *
 * **Idempotent and serialised.** The table is emptied and refilled inside one
 * transaction under an advisory lock: two refreshes never interleave, running
 * one twice gives the same rows, and a storefront reading during a refresh
 * sees the previous set until the new one commits.
 */
class RefreshWebsiteBestSellers
{
    /** The rolling window, in days. */
    public const WINDOW_DAYS = 30;

    /** Advisory lock key: "best sellers". */
    protected const LOCK_KEY = 5_012_026;

    /** Order statuses that undo a sale, whatever happened before. */
    public const EXCLUDED_STATUSES = [
        OrderStatus::Cancelled,
        OrderStatus::RefundPending,
        OrderStatus::PartiallyRefunded,
        OrderStatus::Refunded,
    ];

    /** Returns whose received quantities are subtracted. */
    public const COUNTED_RETURNS = [ReturnStatus::Received, ReturnStatus::Refunded];

    /**
     * @return int how many selections now have sales
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays(self::WINDOW_DAYS);

        $excluded = array_map(fn (OrderStatus $status) => $status->value, self::EXCLUDED_STATUSES);
        $returns = array_map(fn (ReturnStatus $status) => $status->value, self::COUNTED_RETURNS);

        $sql = sprintf(<<<'SQL'
            INSERT INTO website_product_sales (website_product_id, website_id, net_units, order_count, window_starts_at, refreshed_at)
            WITH returned AS (
                SELECT ori.order_item_id, SUM(ori.received_quantity) AS received
                FROM order_return_items ori
                JOIN order_returns r ON r.id = ori.order_return_id
                WHERE r.status IN (%s) AND ori.received_quantity > 0
                GROUP BY ori.order_item_id
            ),
            eligible AS (
                SELECT o.id, o.website_id
                FROM orders o
                JOIN payments p ON p.id = o.payment_id
                WHERE o.website_id IS NOT NULL
                    AND o.source = ?
                    AND o.placed_at >= ?
                    AND o.status NOT IN (%s)
                    AND p.purpose = ?
                    AND (
                        (p.gateway IS NOT NULL AND o.paid_at IS NOT NULL)
                        OR (
                            p.gateway IS NULL
                            AND EXISTS (
                                SELECT 1 FROM order_status_history h
                                WHERE h.order_id = o.id AND h.new_status = ? AND h.source = ?
                            )
                        )
                    )
            ),
            lines AS (
                SELECT e.website_id, oi.product_id, e.id AS order_id,
                    GREATEST(oi.quantity - COALESCE(rt.received, 0), 0) AS net
                FROM eligible e
                JOIN order_items oi ON oi.order_id = e.id
                LEFT JOIN returned rt ON rt.order_item_id = oi.id
                WHERE oi.product_id IS NOT NULL
            ),
            per_product AS (
                SELECT website_id, product_id,
                    SUM(net) AS net_units,
                    COUNT(DISTINCT order_id) FILTER (WHERE net > 0) AS order_count
                FROM lines
                GROUP BY website_id, product_id
            )
            SELECT wp.id, pp.website_id, pp.net_units, pp.order_count, ?, ?
            FROM per_product pp
            JOIN website_products wp ON wp.website_id = pp.website_id AND wp.product_id = pp.product_id
            WHERE pp.net_units > 0
            SQL,
            implode(', ', array_fill(0, count($returns), '?')),
            implode(', ', array_fill(0, count($excluded), '?')),
        );

        $bindings = [
            ...$returns,
            OrderSource::Website->value,
            $since,
            ...$excluded,
            PaymentPurpose::WebsiteOrder->value,
            OrderStatus::Confirmed->value,
            OrderStatusChangeSource::Storefront->value,
            $since,
            $now,
        ];

        return DB::transaction(function () use ($sql, $bindings) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            DB::table('website_product_sales')->delete();

            return DB::affectingStatement($sql, $bindings);
        });
    }
}
