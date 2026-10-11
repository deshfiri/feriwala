<?php

namespace App\Domain\Order\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\ExternalOrderStatusDisplay;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Every order a business has, from every source, as one list (D29).
 *
 * A read across three kinds that stay what they are:
 *
 *   - **website** — orders its Feriwala-hosted websites took (Feriwala is the
 *     merchant of record, D12);
 *   - **wholesale** — what the business bought from Feriwala;
 *   - **external** — the mirror of orders its own stores took elsewhere (D29).
 *
 * Nothing is merged or converted: each row keeps its kind, its own status
 * vocabulary and its own detail screen. Filtering, searching, sorting and paging
 * all happen in the database over a `UNION ALL`, each arm already restricted to
 * this account — so no other account's order can appear however the request
 * is shaped, and no full list is ever loaded.
 */
class BusinessOrderIndex
{
    public const SOURCES = ['website', 'wholesale', 'external'];

    /** Sortable columns, by the name the request uses. */
    public const SORTS = ['placed_at' => 'placed_at', 'total' => 'total', 'number' => 'number'];

    /**
     * @param  array{search: string|null, source: string|null, store: string|null, status: string|null, payment: string|null, from: string|null, to: string|null, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(BusinessAccount $account, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->filtered($account, $filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (stdClass $row) => $this->row($row));
    }

    /**
     * The same rows, unpaged, for an export — capped by the caller.
     *
     * @param  array{search: string|null, source: string|null, store: string|null, status: string|null, payment: string|null, from: string|null, to: string|null, sort: string, direction: string}  $filters
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(BusinessAccount $account, array $filters, int $limit): iterable
    {
        foreach ($this->filtered($account, $filters)->limit($limit)->cursor() as $row) {
            yield $this->row($row);
        }
    }

    /**
     * The filter choices that exist for this account: its stores, and the
     * statuses and payment states its orders have actually been in.
     *
     * @return array{stores: array<int, array{value: string, label: string}>, statuses: array<int, array{value: string, label: string}>, payments: array<int, array{value: string, label: string}>}
     */
    public function options(BusinessAccount $account): array
    {
        $union = DB::query()->fromSub($this->union($account), 'business_orders');

        $stores = (clone $union)
            ->whereNotNull('store_key')
            ->select('store_key', 'store_name')
            ->distinct()
            ->orderBy('store_name')
            ->get()
            ->map(fn (stdClass $row) => ['value' => (string) $row->store_key, 'label' => (string) $row->store_name])
            ->values()
            ->all();

        $statuses = (clone $union)
            ->select('kind', 'status')
            ->distinct()
            ->get()
            ->mapWithKeys(fn (stdClass $row) => [(string) $row->status => $this->statusLabel((string) $row->kind, (string) $row->status)])
            ->sort()
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        $payments = (clone $union)
            ->whereNotNull('payment_state')
            ->select('payment_state')
            ->distinct()
            ->orderBy('payment_state')
            ->pluck('payment_state')
            ->map(fn (mixed $state) => ['value' => (string) $state, 'label' => $this->paymentLabel((string) $state)])
            ->values()
            ->all();

        return ['stores' => $stores, 'statuses' => $statuses, 'payments' => $payments];
    }

    /**
     * @param  array{search: string|null, source: string|null, store: string|null, status: string|null, payment: string|null, from: string|null, to: string|null, sort: string, direction: string}  $filters
     */
    protected function filtered(BusinessAccount $account, array $filters): Builder
    {
        $term = $filters['search'];
        $column = self::SORTS[$filters['sort']] ?? 'placed_at';
        $direction = $filters['direction'] === 'asc' ? 'asc' : 'desc';

        return DB::query()
            ->fromSub($this->union($account), 'business_orders')
            ->when($filters['source'] !== null, fn (Builder $query) => $query->where('kind', $filters['source']))
            ->when($filters['store'] !== null, fn (Builder $query) => $query->where('store_key', $filters['store']))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['payment'] !== null, fn (Builder $query) => $query->where('payment_state', $filters['payment']))
            ->when($filters['from'] !== null, fn (Builder $query) => $query->whereDate('placed_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn (Builder $query) => $query->whereDate('placed_at', '<=', $filters['to']))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'ilike', "%{$term}%")
                ->orWhere('secondary_number', 'ilike', "%{$term}%")
                ->orWhere('customer_name', 'ilike', "%{$term}%")
                ->orWhere('customer_phone', 'ilike', "%{$term}%")))
            ->orderBy($column, $direction)
            ->orderBy('kind')
            ->orderBy('public_id', $direction);
    }

    /**
     * The three arms, each restricted to this account before anything else.
     */
    protected function union(BusinessAccount $account): Builder
    {
        $paymentState = <<<'SQL'
            CASE p.status
                WHEN 'draft' THEN 'awaiting'
                WHEN 'initiated' THEN 'awaiting'
                WHEN 'pending' THEN 'confirming'
                WHEN 'paid' THEN 'paid'
                WHEN 'failed' THEN 'failed'
                WHEN 'cancelled' THEN 'cancelled'
                WHEN 'reconciliation_required' THEN 'reconciliation'
                WHEN 'refunded' THEN 'refunded'
                WHEN 'partially_refunded' THEN 'refunded'
            END
        SQL;

        $website = DB::table('orders as o')
            ->leftJoin('websites as w', 'w.id', '=', 'o.website_id')
            ->leftJoin('payments as p', 'p.id', '=', 'o.payment_id')
            ->where('o.business_account_id', $account->id)
            ->where('o.source', OrderSource::Website->value)
            ->selectRaw("'website' as kind")
            ->addSelect(['o.public_id', 'o.reference as number', 'o.storefront_order_reference as secondary_number'])
            ->selectRaw("'website:' || w.public_id as store_key, w.name as store_name, w.public_id as store_public_id")
            ->selectRaw("o.customer->>'name' as customer_name, o.customer->>'mobile' as customer_phone")
            ->addSelect(['o.total', 'o.currency_code'])
            ->selectRaw("{$paymentState} as payment_state")
            ->addSelect(['o.status', 'o.fulfillment_status', 'o.delivery_status', 'o.placed_at']);

        $wholesale = DB::table('orders as o')
            ->leftJoin('payments as p', 'p.id', '=', 'o.payment_id')
            ->where('o.business_account_id', $account->id)
            ->where('o.source', OrderSource::ErpWholesale->value)
            ->selectRaw("'wholesale' as kind")
            ->addSelect(['o.public_id', 'o.reference as number'])
            ->selectRaw('NULL as secondary_number, NULL as store_key, NULL as store_name, NULL as store_public_id')
            ->selectRaw("o.customer->>'name' as customer_name, o.customer->>'mobile' as customer_phone")
            ->addSelect(['o.total', 'o.currency_code'])
            ->selectRaw("{$paymentState} as payment_state")
            ->addSelect(['o.status', 'o.fulfillment_status', 'o.delivery_status', 'o.placed_at']);

        $external = DB::table('external_orders as eo')
            ->join('external_stores as es', 'es.id', '=', 'eo.external_store_id')
            ->where('eo.business_account_id', $account->id)
            ->where('es.business_account_id', $account->id)
            ->selectRaw("'external' as kind")
            ->addSelect(['eo.public_id', 'eo.order_number as number'])
            ->selectRaw("NULL as secondary_number, 'external:' || es.public_id as store_key, es.name as store_name, es.public_id as store_public_id")
            ->selectRaw("eo.customer->>'name' as customer_name, eo.customer->>'phone' as customer_phone")
            ->addSelect(['eo.total', 'eo.currency_code'])
            ->selectRaw('eo.payment_status as payment_state')
            ->selectRaw('eo.source_status as status, NULL as fulfillment_status, NULL as delivery_status')
            ->addSelect('eo.placed_at');

        return $website->unionAll($wholesale)->unionAll($external);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(stdClass $row): array
    {
        $kind = (string) $row->kind;
        $status = (string) $row->status;
        $fulfilment = $row->fulfillment_status === null ? null : OrderFulfillmentStatus::tryFrom((string) $row->fulfillment_status);
        $delivery = $row->delivery_status === null ? null : OrderDeliveryStatus::tryFrom((string) $row->delivery_status);
        $currency = Currency::tryFrom((string) $row->currency_code) ?? Currency::base();

        return [
            'id' => (string) $row->public_id,
            'kind' => $kind,
            'kind_label' => __('orders.unified.sources.'.$kind),
            'number' => (string) $row->number,
            'secondary_number' => $row->secondary_number === null ? null : (string) $row->secondary_number,
            'store' => $row->store_name === null ? null : (string) $row->store_name,
            'customer' => $row->customer_name === null ? null : (string) $row->customer_name,
            'customer_phone' => $row->customer_phone === null ? null : (string) $row->customer_phone,
            'total' => Money::fromDecimal((string) $row->total, $currency)->jsonSerialize(),
            'payment_state' => $row->payment_state === null ? null : (string) $row->payment_state,
            'payment_label' => $row->payment_state === null ? null : $this->paymentLabel((string) $row->payment_state),
            'payment_tone' => $this->paymentTone($row->payment_state === null ? null : (string) $row->payment_state),
            'status' => $status,
            'status_label' => $this->statusLabel($kind, $status),
            'status_tone' => $kind === 'external'
                ? ExternalOrderStatusDisplay::tone($status)
                : (OrderStatus::tryFrom($status)?->tone() ?? 'neutral'),
            'fulfillment' => $fulfilment === null ? null : ['label' => $fulfilment->label(), 'tone' => $fulfilment->tone()],
            'delivery' => $delivery === null ? null : ['label' => $delivery->label(), 'tone' => $delivery->tone()],
            'placed_at' => CarbonImmutable::parse((string) $row->placed_at)->toIso8601String(),
            'href' => match ($kind) {
                'website' => route('websites.orders.show', [(string) $row->store_public_id, (string) $row->public_id]),
                'wholesale' => route('wholesale.orders.show', (string) $row->public_id),
                default => route('orders.external.show', (string) $row->public_id),
            },
        ];
    }

    protected function statusLabel(string $kind, string $status): string
    {
        if ($kind === 'external') {
            return (string) ExternalOrderStatusDisplay::label($status);
        }

        return OrderStatus::tryFrom($status)?->label() ?? $status;
    }

    protected function paymentLabel(string $state): string
    {
        $key = 'orders.unified.payment_states.'.$state;
        $line = __($key);

        return $line === $key ? (string) ExternalOrderStatusDisplay::label($state) : $line;
    }

    protected function paymentTone(?string $state): string
    {
        return match ($state) {
            'paid' => 'success',
            'awaiting', 'confirming', 'unpaid', 'pending', 'partially_paid' => 'warning',
            'failed', 'reconciliation' => 'danger',
            null => 'neutral',
            default => 'neutral',
        };
    }
}
