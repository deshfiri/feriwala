<?php

namespace App\Domain\Order\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * A Storefront profit as the Partner and staff read it: where it stands, how
 * much, and when it is credited. Figures come from the server only.
 */
class StorefrontProfitSummary
{
    /**
     * One order's profit, or null when it has none yet.
     *
     * @return array{status: string, status_label: string, status_tone: string, reason: string|null, profit: array<string, mixed>|null, credited: array<string, mixed>, reversed: array<string, mixed>, release_at: string|null, released_at: string|null, reported_only: bool}|null
     */
    public function forOrder(Order $order): ?array
    {
        $profit = PartnerStorefrontProfit::query()->where('order_id', $order->id)->first();

        if ($profit === null) {
            return null;
        }

        return [
            'status' => $profit->status->value,
            'status_label' => $profit->status->label(),
            'status_tone' => $profit->status->tone(),
            'reason' => $profit->status_reason === null ? null : $this->reason($profit->status_reason),
            'profit' => $profit->profit_amount?->jsonSerialize(),
            'credited' => $profit->netCredited()->jsonSerialize(),
            'reversed' => $profit->reversed_amount->jsonSerialize(),
            'release_at' => $profit->release_at?->toIso8601String(),
            'released_at' => $profit->released_at?->toIso8601String(),
            'reported_only' => $profit->status === StorefrontProfitStatus::ReportedOnly,
        ];
    }

    /**
     * What is on its way to the Partner's withdrawable balance: profits holding
     * or waiting on facts, with the soonest release time.
     *
     * @return array{amount: array<string, mixed>, count: int, next_release_at: string|null}
     */
    public function pendingFor(BusinessAccount $account, Currency $currency): array
    {
        $pending = PartnerStorefrontProfit::query()
            ->where('business_account_id', $account->id)
            ->whereIn('status', [StorefrontProfitStatus::Holding->value, StorefrontProfitStatus::AwaitingFacts->value, StorefrontProfitStatus::Blocked->value])
            ->whereNotNull('profit_amount')
            ->get();

        $amount = $pending->reduce(
            fn (Money $sum, PartnerStorefrontProfit $profit) => $profit->profit_amount !== null && $profit->profit_amount->isPositive()
                ? $sum->plus($profit->profit_amount->minus($profit->legacy_credited_amount))
                : $sum,
            Money::zero($currency),
        );

        $next = $pending->where('status', StorefrontProfitStatus::Holding)->whereNotNull('release_at')->sortBy('release_at')->first();

        return [
            'amount' => $amount->jsonSerialize(),
            'count' => $pending->count(),
            'next_release_at' => $next?->release_at?->toIso8601String(),
        ];
    }

    protected function reason(string $code): string
    {
        $key = 'storefront_profit.reasons.'.$code;
        $line = __($key);

        return is_string($line) && $line !== $key ? $line : $code;
    }
}
