<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Order\Models\PartnerStorefrontProfitReversal;
use Carbon\CarbonImmutable;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * The minute sweep for Storefront profits.
 *
 *   1. credits every profit whose 24-hour hold has ended, each checked once
 *      more under its own lock;
 *   2. looks again at every blocked profit, so one whose return or refund has
 *      been resolved goes back to holding;
 *   3. takes every owed reversal the Partner's Wallet can now carry;
 *   4. catches up a delivered Storefront order that has no profit yet — one
 *      whose after-commit update failed.
 *
 * Each row on its own: one that fails is logged and left for the next run.
 */
class ReleaseDueStorefrontProfits
{
    protected const BATCH = 500;

    public function __construct(
        protected SettleStorefrontProfit $settle,
        protected LogManager $log,
    ) {}

    /**
     * @return array{released: int, unblocked: int, recovered: int, caught_up: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['released' => 0, 'unblocked' => 0, 'recovered' => 0, 'caught_up' => 0];

        PartnerStorefrontProfit::query()->due($now)->orderBy('release_at')->limit(self::BATCH)->pluck('id')
            ->each(function (int $id) use ($now, &$counts): void {
                if ($this->settle->release($id, $now)->status === StorefrontProfitStatus::Released) {
                    $counts['released']++;
                }
            });

        PartnerStorefrontProfit::query()->where('status', StorefrontProfitStatus::Blocked->value)->orderBy('updated_at')->limit(self::BATCH)->get()
            ->each(function (PartnerStorefrontProfit $profit) use ($now, &$counts): void {
                $this->quietly('unblock', $profit->public_id, function () use ($profit, $now, &$counts): void {
                    $synced = $this->settle->sync($profit->order);

                    if ($synced === null || $synced->status === StorefrontProfitStatus::Blocked) {
                        return;
                    }

                    $counts['unblocked']++;

                    // Its hold may well have ended while it was blocked.
                    if ($synced->status === StorefrontProfitStatus::Holding && $synced->release_at?->lessThanOrEqualTo($now) === true
                        && $this->settle->release($synced->id, $now)->status === StorefrontProfitStatus::Released) {
                        $counts['released']++;
                    }
                });
            });

        PartnerStorefrontProfitReversal::query()->where('status', PartnerStorefrontProfitReversal::STATUS_OWED)->orderBy('created_at')->limit(self::BATCH)->get()
            ->each(function (PartnerStorefrontProfitReversal $reversal) use (&$counts): void {
                $this->quietly('recover', $reversal->public_id, function () use ($reversal, &$counts): void {
                    if ($this->settle->recover($reversal)->status === PartnerStorefrontProfitReversal::STATUS_POSTED) {
                        $counts['recovered']++;
                    }
                });
            });

        Order::query()
            ->where('source', OrderSource::Website->value)
            ->whereIn('delivery_status', [OrderDeliveryStatus::Delivered->value])
            ->whereNotExists(fn ($query) => $query->from('partner_storefront_profits')->whereColumn('partner_storefront_profits.order_id', 'orders.id'))
            ->limit(self::BATCH)
            ->get()
            ->each(function (Order $order) use (&$counts): void {
                $this->quietly('catch_up', $order->reference, function () use ($order, &$counts): void {
                    $this->settle->sync($order);
                    $counts['caught_up']++;
                });
            });

        return $counts;
    }

    protected function quietly(string $step, string $subject, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $throwable) {
            $this->log->channel('wallet')->error('A Storefront profit sweep step failed', [
                'step' => $step,
                'subject' => $subject,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
