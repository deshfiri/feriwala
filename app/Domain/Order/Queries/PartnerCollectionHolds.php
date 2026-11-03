<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\OrderReceivableCollection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which of a Partner's Partner-gateway orders are still entitled to Wallet money
 * (D36).
 *
 * A hold is **live** while Banij may still collect it: the collection is pending
 * or short of money, its deadline has not passed, and the order is still waiting.
 * It stops being live the moment any of those stops being true — collected,
 * expired and waiting for a refund, sent to staff because stock could not be
 * secured, or the order cancelled. Only a live hold stands between a Partner and a
 * withdrawal, so a Partner is never kept from their own money by something Banij
 * can no longer collect.
 */
class PartnerCollectionHolds
{
    /**
     * @return Builder<OrderReceivableCollection>
     */
    public function live(int $businessAccountId, ?CarbonImmutable $now = null): Builder
    {
        return OrderReceivableCollection::query()
            ->active()
            ->where('business_account_id', $businessAccountId)
            ->where('deadline_at', '>', $now ?? CarbonImmutable::now())
            ->whereHas('order', fn (Builder $order) => $order->whereIn('status', [
                OrderStatus::PaymentPending->value,
                OrderStatus::OnHold->value,
            ]));
    }

    /**
     * The oldest live hold, with its order, or null when the Partner may withdraw.
     */
    public function firstLive(int $businessAccountId, ?CarbonImmutable $now = null): ?OrderReceivableCollection
    {
        return $this->live($businessAccountId, $now)->with('order')->first();
    }
}
