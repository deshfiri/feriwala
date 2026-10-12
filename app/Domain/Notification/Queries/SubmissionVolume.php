<?php

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Enums\StaffQueue;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Submissions and requests received per day across the staff queues, for the
 * admin dashboard's second trend (§36.1).
 *
 * Counts rows by the day they arrived, over only the queues this viewer may
 * open, so the chart can never reveal volume in a queue the cards hide from
 * them. A day with nothing is a real answer and stays on the axis.
 */
class SubmissionVolume
{
    /** How many days the trend covers, counting today. */
    public const DAYS = 14;

    /**
     * @return array{key: string, label: string, tone: int, points: array<int, array{label: string, value: int, formatted: string}>}|null
     *                                                                                                                                    Null when the viewer may open none of the queues.
     */
    public function series(User $viewer): ?array
    {
        $queues = array_filter(StaffQueue::cases(), fn (StaffQueue $queue) => $queue->mayBeSeenBy($viewer));

        if ($queues === []) {
            return null;
        }

        $start = Carbon::now()->startOfDay()->subDays(self::DAYS - 1);
        $perDay = [];

        foreach ($queues as $queue) {
            // toBase() keeps Money-cast columns (an amount) out of the aggregate.
            $rows = $queue->model()::query()
                ->where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, count(*) as total')
                ->groupBy('day')
                ->toBase()
                ->pluck('total', 'day');

            foreach ($rows as $day => $total) {
                $perDay[$day] = ($perDay[$day] ?? 0) + (int) $total;
            }
        }

        $points = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $day = $start->copy()->addDays($offset);
            $count = $perDay[$day->format('Y-m-d')] ?? 0;

            $points[] = [
                'label' => $day->translatedFormat('D'),
                'value' => $count,
                'formatted' => (string) $count,
            ];
        }

        return [
            'key' => 'submissions',
            'label' => __('dashboard.admin.trend.submissions'),
            'tone' => 2,
            'points' => $points,
        ];
    }
}
