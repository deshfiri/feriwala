<?php

namespace App\Domain\Order\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\ExternalOrderStatusDisplay;
use App\Domain\ExternalStore\Models\ExternalCustomer;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\WebsiteCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The people who bought from a business, store by store (D29).
 *
 * Two sources, never merged: the customers its Feriwala-hosted websites know,
 * and the customers its external stores reported. A customer is always one
 * store's customer — two records with the same phone number in two stores stay
 * two records, because nothing proves they are one person. Every row is reached
 * through this account's own websites and stores.
 */
class CustomerDirectory
{
    /** Order statuses that count as delivered, cancelled or returned. */
    private const WEBSITE_DELIVERED = ['delivered', 'completed'];

    private const WEBSITE_CANCELLED = ['cancelled'];

    private const WEBSITE_RETURNED = ['return_requested', 'return_approved', 'returning', 'returned', 'refund_pending', 'partially_refunded', 'refunded'];

    private const EXTERNAL_DELIVERED = ['delivered', 'completed'];

    private const EXTERNAL_CANCELLED = ['cancelled'];

    private const EXTERNAL_RETURNED = ['returned', 'refunded'];

    /**
     * @param  array{search: string|null, source: string|null, store: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(BusinessAccount $account, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $term = $filters['search'];

        return DB::query()
            ->fromSub($this->union($account), 'business_customers')
            ->when($filters['source'] !== null, fn (Builder $query) => $query->where('kind', $filters['source']))
            ->when($filters['store'] !== null, fn (Builder $query) => $query->where('store_key', $filters['store']))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('phone', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")))
            ->orderByRaw('last_order_at DESC NULLS LAST')
            ->orderBy('public_id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (stdClass $row) => $this->row($row));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(stdClass $row): array
    {
        return [
            'id' => (string) $row->public_id,
            'kind' => (string) $row->kind,
            'kind_label' => __('orders.unified.sources.'.$row->kind),
            'name' => $row->name === null ? null : (string) $row->name,
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'email' => $row->email === null ? null : (string) $row->email,
            'store' => (string) $row->store_name,
            'orders_count' => (int) $row->orders_count,
            'last_order_at' => $row->last_order_at === null ? null : CarbonImmutable::parse((string) $row->last_order_at)->toIso8601String(),
            'href' => route('customers.show', [(string) $row->kind, (string) $row->public_id]),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function storeOptions(BusinessAccount $account): array
    {
        return DB::query()
            ->fromSub($this->union($account), 'business_customers')
            ->select('store_key', 'store_name')
            ->distinct()
            ->orderBy('store_name')
            ->get()
            ->map(fn (stdClass $row) => ['value' => (string) $row->store_key, 'label' => (string) $row->store_name])
            ->values()
            ->all();
    }

    /**
     * One customer with their orders in that store, or null when the customer
     * is not this account's.
     *
     * @return array<string, mixed>|null
     */
    public function detail(BusinessAccount $account, string $kind, string $publicId): ?array
    {
        return $kind === 'external'
            ? $this->externalDetail($account, $publicId)
            : $this->websiteDetail($account, $publicId);
    }

    protected function union(BusinessAccount $account): Builder
    {
        $website = DB::table('website_customers as c')
            ->join('websites as w', 'w.id', '=', 'c.website_id')
            ->where('w.business_account_id', $account->id)
            ->selectRaw("'website' as kind, c.public_id, c.name, c.mobile as phone, c.email")
            ->selectRaw("'website:' || w.public_id as store_key, w.name as store_name")
            ->selectRaw('(SELECT count(*) FROM orders o WHERE o.website_customer_id = c.id AND o.business_account_id = ?) as orders_count', [$account->id])
            ->addSelect('c.last_order_at');

        $external = DB::table('external_customers as c')
            ->join('external_stores as s', 's.id', '=', 'c.external_store_id')
            ->where('c.business_account_id', $account->id)
            ->where('s.business_account_id', $account->id)
            ->selectRaw("'external' as kind, c.public_id, c.name, c.phone, c.email")
            ->selectRaw("'external:' || s.public_id as store_key, s.name as store_name")
            ->selectRaw('(SELECT count(*) FROM external_orders eo WHERE eo.external_customer_id = c.id) as orders_count')
            ->addSelect('c.last_order_at');

        return $website->unionAll($external);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function websiteDetail(BusinessAccount $account, string $publicId): ?array
    {
        /** @var WebsiteCustomer|null $customer */
        $customer = WebsiteCustomer::query()
            ->where('public_id', $publicId)
            ->whereHas('website', fn ($query) => $query->where('business_account_id', $account->id))
            ->with('website')
            ->first();

        if ($customer === null) {
            return null;
        }

        $orders = Order::query()
            ->where('business_account_id', $account->id)
            ->where('source', OrderSource::Website)
            ->where('website_customer_id', $customer->id)
            ->orderByDesc('placed_at')
            ->get();

        $statuses = $orders->map(fn (Order $order) => $order->status->value);

        return [
            'id' => $customer->public_id,
            'kind' => 'website',
            'kind_label' => __('orders.unified.sources.website'),
            'name' => $customer->name,
            'phone' => $customer->mobile,
            'email' => $customer->email,
            'store' => $customer->website->name,
            'stats' => [
                'total' => $orders->count(),
                'delivered' => $statuses->intersect(self::WEBSITE_DELIVERED)->count(),
                'cancelled' => $statuses->intersect(self::WEBSITE_CANCELLED)->count(),
                'returned' => $statuses->intersect(self::WEBSITE_RETURNED)->count(),
            ],
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->public_id,
                'number' => $order->reference,
                'status_label' => $order->status->label(),
                'status_tone' => $order->status->tone(),
                'total' => $order->total->jsonSerialize(),
                'placed_at' => $order->placed_at->toIso8601String(),
                'href' => route('websites.orders.show', [$customer->website->public_id, $order->public_id]),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function externalDetail(BusinessAccount $account, string $publicId): ?array
    {
        /** @var ExternalCustomer|null $customer */
        $customer = ExternalCustomer::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $publicId)
            ->with('store')
            ->first();

        if ($customer === null) {
            return null;
        }

        $orders = ExternalOrder::query()
            ->where('business_account_id', $account->id)
            ->where('external_customer_id', $customer->id)
            ->orderByDesc('placed_at')
            ->get();

        $statuses = $orders->map(fn (ExternalOrder $order) => $order->source_status);

        return [
            'id' => $customer->public_id,
            'kind' => 'external',
            'kind_label' => __('orders.unified.sources.external'),
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'store' => $customer->store->name,
            'stats' => [
                'total' => $orders->count(),
                'delivered' => $statuses->intersect(self::EXTERNAL_DELIVERED)->count(),
                'cancelled' => $statuses->intersect(self::EXTERNAL_CANCELLED)->count(),
                'returned' => $statuses->intersect(self::EXTERNAL_RETURNED)->count(),
            ],
            'orders' => $orders->map(fn (ExternalOrder $order) => [
                'id' => $order->public_id,
                'number' => $order->order_number,
                'status_label' => ExternalOrderStatusDisplay::label($order->source_status),
                'status_tone' => ExternalOrderStatusDisplay::tone($order->source_status),
                'total' => $order->total->jsonSerialize(),
                'placed_at' => $order->placed_at->toIso8601String(),
                'href' => route('orders.external.show', $order->public_id),
            ])->all(),
        ];
    }
}
