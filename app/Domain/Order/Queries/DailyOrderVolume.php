<?php

namespace App\Domain\Order\Queries;

use App\Domain\Billing\Queries\AccountSpendSummary;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Orders placed per day, most recent {@see DailyOrderVolume::DAYS} days
 * (Admin dashboard's one primary trend chart).
 *
 * A day with no orders is a real answer and belongs on the axis rather than a
 * gap — the same reasoning {@see AccountSpendSummary}
 * applies to a month with no payments. Plain counts, so unlike that query
 * there is no Money/bcmath conversion: the count itself is already the chart's
 * plottable integer (§36.1's money rule does not apply to counting rows).
 */
class DailyOrderVolume
{
    /** How many days the trend covers, counting today. */
    public const DAYS = 14;

    /**
     * @return array{key: string, label: string, tone: int, points: array<int, array{label: string, value: int, formatted: string}>}
     */
    public function series(): array
    {
        $start = Carbon::now()->startOfDay()->subDays(self::DAYS - 1);

        // toBase() so this returns plain rows: Order's own `total` column is a
        // Money cast, and an aggregate alias sharing that name would otherwise
        // be hydrated through it and come back as a Money, not a plain count.
        $counts = Order::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, count(*) as orders_count')
            ->groupBy('day')
            ->toBase()
            ->pluck('orders_count', 'day');

        $points = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $day = $start->copy()->addDays($offset);
            $count = (int) ($counts[$day->format('Y-m-d')] ?? 0);

            $points[] = [
                'label' => $day->translatedFormat('D'),
                'value' => $count,
                'formatted' => (string) $count,
            ];
        }

        return [
            'key' => 'orders',
            'label' => __('dashboard.admin.trend.orders'),
            'tone' => 1,
            'points' => $points,
        ];
    }
}
