<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What an account has actually paid Feriwala, and when (§26, §33.3).
 *
 * Self-scoped by construction (§31.3): the account arrives as a model resolved
 * from the signed-in person's own membership, so there is no identifier in the
 * query for anyone to substitute.
 *
 * Only settled payments count. A pending payment looks like money on a gateway's
 * redirect page and is not money here — putting it on the chart would tell an
 * account holder they had spent something they may yet not.
 *
 * Every figure stays in integer minor units the whole way through, including the
 * values handed to the chart and the y-axis ticks. The front end plots the
 * integer and prints the string beside it; nothing divides by a hundred in
 * JavaScript (§36.1, D4).
 */
class AccountSpendSummary
{
    /** How many months the trend covers, counting the current one. */
    public const MONTHS = 6;

    /** Gridlines on the y axis, excluding the zero line. */
    protected const TICK_COUNT = 4;

    /**
     * @return array{
     *     total: Money,
     *     months: int,
     *     series: array<int, array{key: string, label: string, tone: int, points: array<int, array{label: string, value: int, formatted: string}>}>,
     *     ticks: array<int, array{value: int, label: string}>,
     *     breakdown: array<int, array{key: string, label: string, value: int, formatted: string}>,
     * }
     */
    public function forAccount(BusinessAccount $account): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        $payments = Payment::query()
            ->where('business_account_id', $account->id)
            ->whereIn('status', [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded])
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->orderBy('completed_at')
            ->get();

        $monthly = $this->monthlyTotals($payments, $start);
        $peak = $this->peak($monthly);

        return [
            'total' => $this->sum($monthly),
            'months' => self::MONTHS,
            'series' => [[
                'key' => 'spend',
                'label' => __('dashboard.spend.series'),
                'tone' => 1,
                'points' => $this->points($monthly),
            ]],
            'ticks' => $this->ticks($peak),
            'breakdown' => $this->breakdown($payments),
        ];
    }

    /**
     * One bucket per month in range, including the months with nothing in them.
     *
     * A month with no payments is a real answer and belongs on the axis. Dropping
     * it would silently compress the timeline and make a gap look like a
     * continuous run.
     *
     * The label is written here, where the date object still exists, rather than
     * parsed back out of the bucket key later. Translated server-side, so the
     * axis reads in Bangla when the rest of the page does (D6).
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array{label: string, amount: Money}>
     */
    protected function monthlyTotals(Collection $payments, Carbon $start): array
    {
        $buckets = [];

        for ($offset = 0; $offset < self::MONTHS; $offset++) {
            $month = $start->copy()->addMonths($offset);

            $buckets[$month->format('Y-m')] = [
                'label' => $month->translatedFormat('M'),
                'amount' => Money::zero(),
            ];
        }

        foreach ($payments as $payment) {
            $key = $payment->completed_at?->format('Y-m');

            if ($key === null || ! array_key_exists($key, $buckets)) {
                continue;
            }

            $buckets[$key]['amount'] = $buckets[$key]['amount']->plus($payment->amount_minor);
        }

        return array_values($buckets);
    }

    /**
     * @param  array<int, array{label: string, amount: Money}>  $monthly
     * @return array<int, array{label: string, value: int, formatted: string}>
     */
    protected function points(array $monthly): array
    {
        return array_map(fn (array $month) => [
            'label' => $month['label'],
            'value' => $month['amount']->minorUnits,
            'formatted' => $month['amount']->format(),
        ], $monthly);
    }

    /**
     * @param  array<int, array{label: string, amount: Money}>  $monthly
     */
    protected function sum(array $monthly): Money
    {
        return array_reduce(
            $monthly,
            fn (Money $carry, array $month) => $carry->plus($month['amount']),
            Money::zero(),
        );
    }

    /**
     * @param  array<int, array{label: string, amount: Money}>  $monthly
     */
    protected function peak(array $monthly): Money
    {
        return array_reduce(
            $monthly,
            fn (Money $carry, array $month) => $month['amount']->greaterThan($carry)
                ? $month['amount']
                : $carry,
            Money::zero(),
        );
    }

    /**
     * Y-axis gridlines, each carrying the label it should print.
     *
     * Rounded up to a readable step so the top line sits above the tallest point
     * rather than clipping it, and so the axis reads 0 / 5,000 / 10,000 instead
     * of 0 / 4,317 / 8,634.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function ticks(Money $peak): array
    {
        if ($peak->isZero()) {
            return [['value' => 0, 'label' => Money::zero()->format()]];
        }

        $step = $this->niceStep((int) ceil($peak->minorUnits / self::TICK_COUNT));

        $ticks = [];

        for ($index = 0; $index <= self::TICK_COUNT; $index++) {
            $value = $step * $index;

            $ticks[] = [
                'value' => $value,
                'label' => Money::of($value, $peak->currency)->format(),
            ];
        }

        return $ticks;
    }

    /**
     * Round a step up to one or two significant figures.
     *
     * Integer arithmetic throughout — this operates on minor units, and a float
     * here would be the one place money drifted (D4).
     */
    protected function niceStep(int $step): int
    {
        $step = max(1, $step);
        $magnitude = 10 ** max(0, strlen((string) $step) - 2);

        return (int) (ceil($step / $magnitude) * $magnitude);
    }

    /**
     * Spend split by what it was for, largest first.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array{key: string, label: string, value: int, formatted: string}>
     */
    protected function breakdown(Collection $payments): array
    {
        /** @var array<string, Money> $totals */
        $totals = [];

        foreach ($payments as $payment) {
            $key = $payment->purpose->value;
            $totals[$key] = ($totals[$key] ?? Money::zero())->plus($payment->amount_minor);
        }

        uasort($totals, fn (Money $a, Money $b) => $b->minorUnits <=> $a->minorUnits);

        $slices = [];

        foreach ($totals as $key => $amount) {
            $slices[] = [
                'key' => $key,
                'label' => PaymentPurpose::from($key)->label(),
                'value' => $amount->minorUnits,
                'formatted' => $amount->format(),
            ];
        }

        return $slices;
    }
}
