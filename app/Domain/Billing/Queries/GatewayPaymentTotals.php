<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Models\Payment;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * How much each payment gateway has taken (§26.4, §42).
 *
 * Only settled payments count — paid, or paid and partly refunded — the same
 * rule {@see Payment::scopeSettled()} states for the whole billing module. A
 * payment still pending at a gateway is not money here, and a fully refunded
 * one went back.
 *
 * The sum is Postgres's own over a `NUMERIC(19,2)` column, which is exact and
 * comes back as a decimal string; it becomes `Money` through `fromDecimal()`
 * and nothing in between is ever a float. Totals are kept per currency, never
 * added across currencies: a gateway that took Taka and dollars shows both.
 */
class GatewayPaymentTotals
{
    /**
     * @param  list<string>  $gateways  The gateways to report on, in display order.
     * @return array{
     *     by_gateway: array<string, array{received: list<Money>, payments: int, last_paid_at: string|null}>,
     *     received: list<Money>,
     *     payments: int,
     * }
     */
    public function handle(array $gateways): array
    {
        /** @var list<object{gateway: string, currency_code: string, total: string|null, payments: int|string, last_paid_at: string|null}> $rows */
        $rows = Payment::query()
            ->settled()
            ->whereIn('gateway', $gateways)
            ->toBase()
            ->select('gateway', 'currency_code')
            ->selectRaw('sum(amount) as total, count(*) as payments, max(completed_at) as last_paid_at')
            ->groupBy('gateway', 'currency_code')
            ->get()
            ->all();

        /** @var array<string, array{received: array<string, Money>, payments: int, last_paid_at: CarbonImmutable|null}> $byGateway */
        $byGateway = [];

        /** @var array<string, Money> $overall */
        $overall = [];
        $overallPayments = 0;

        foreach ($gateways as $gateway) {
            $byGateway[$gateway] = ['received' => [], 'payments' => 0, 'last_paid_at' => null];
        }

        foreach ($rows as $row) {
            $currency = Currency::tryFrom($row->currency_code);

            if ($currency === null || $row->total === null) {
                continue;
            }

            /** @var numeric-string $total */
            $total = $row->total;
            $amount = Money::fromDecimal($total, $currency);
            $count = (int) $row->payments;
            $entry = &$byGateway[$row->gateway];

            $entry['received'][$currency->value] = isset($entry['received'][$currency->value])
                ? $entry['received'][$currency->value]->plus($amount)
                : $amount;
            $entry['payments'] += $count;

            if ($row->last_paid_at !== null) {
                $paidAt = CarbonImmutable::parse($row->last_paid_at);

                if ($entry['last_paid_at'] === null || $paidAt->greaterThan($entry['last_paid_at'])) {
                    $entry['last_paid_at'] = $paidAt;
                }
            }

            unset($entry);

            $overall[$currency->value] = isset($overall[$currency->value])
                ? $overall[$currency->value]->plus($amount)
                : $amount;
            $overallPayments += $count;
        }

        return [
            'by_gateway' => array_map(fn (array $entry) => [
                'received' => $this->ordered($entry['received']),
                'payments' => $entry['payments'],
                'last_paid_at' => $entry['last_paid_at']?->translatedFormat('j M Y, g:i A'),
            ], $byGateway),
            'received' => $this->ordered($overall),
            'payments' => $overallPayments,
        ];
    }

    /**
     * Taka first, then the rest alphabetically; a zero Taka when nothing was
     * received, so there is always a figure to show rather than a blank.
     *
     * @param  array<string, Money>  $amounts
     * @return list<Money>
     */
    protected function ordered(array $amounts): array
    {
        if ($amounts === []) {
            return [Money::zero(Currency::BDT)];
        }

        uksort($amounts, fn (string $a, string $b) => [$a !== Currency::BDT->value, $a] <=> [$b !== Currency::BDT->value, $b]);

        return array_values($amounts);
    }
}
