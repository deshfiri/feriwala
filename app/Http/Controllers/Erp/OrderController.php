<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\ExternalOrderStatusDisplay;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderItem;
use App\Domain\Order\Queries\BusinessOrderIndex;
use App\Domain\Order\Queries\OrderTimeline;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The business's orders, from every source, in one place (D29).
 *
 * Read-only, and the reader needs `order:view` in their own account. Every
 * query starts from that account; an order of another business is not found.
 * An external order is the store's own record: no Feriwala action changes it.
 */
class OrderController extends Controller
{
    use ResolvesBusinessAccount;

    public const PER_PAGE = 25;

    /** The most rows one export carries. */
    public const EXPORT_LIMIT = 10000;

    public function __construct(
        protected BusinessOrderIndex $orders,
        protected OrderTimeline $timeline,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->readerAccount($request);
        $filters = $this->filters($request);

        return Inertia::render('orders/index', [
            'orders' => $this->orders->paginate($account, $filters, self::PER_PAGE),
            'filters' => $filters,
            'options' => [
                ...$this->orders->options($account),
                'sources' => array_map(fn (string $source) => [
                    'value' => $source,
                    'label' => __('orders.unified.sources.'.$source),
                ], BusinessOrderIndex::SOURCES),
            ],
        ]);
    }

    /**
     * The filtered list as CSV, this account's rows only. Cells that a
     * spreadsheet would run as a formula are neutralised.
     */
    public function export(Request $request): StreamedResponse
    {
        $account = $this->readerAccount($request);
        $filters = $this->filters($request);
        $rows = $this->orders->rows($account, $filters, self::EXPORT_LIMIT);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, array_map(fn (string $column) => __('orders.unified.csv.'.$column), [
                'number', 'source', 'store', 'customer', 'phone', 'total', 'currency',
                'payment', 'status', 'fulfillment', 'delivery', 'placed_at',
            ]));

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $value) => $this->cell($value), [
                    $row['number'],
                    $row['kind_label'],
                    $row['store'],
                    $row['customer'],
                    $row['customer_phone'],
                    $row['total']['amount'] ?? null,
                    $row['total']['currency'] ?? null,
                    $row['payment_label'],
                    $row['status_label'],
                    $row['fulfillment']['label'] ?? null,
                    $row['delivery']['label'] ?? null,
                    $row['placed_at'],
                ]));
            }

            fclose($out);
        }, 'orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function showExternal(Request $request, string $order): Response
    {
        $account = $this->readerAccount($request);

        /** @var ExternalOrder|null $found */
        $found = ExternalOrder::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $order)
            ->with(['store', 'items', 'events', 'externalCustomer'])
            ->first();

        abort_if($found === null, 404);

        return Inertia::render('orders/external', [
            'order' => $this->externalDetail($found),
            'timeline' => $this->timeline->forExternalOrder($found),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function externalDetail(ExternalOrder $order): array
    {
        $customer = $order->externalCustomer;

        return [
            'id' => $order->public_id,
            'number' => $order->order_number,
            'external_id' => $order->external_id,
            'platform' => $order->platform->label(),
            'store' => [
                'id' => $order->store->public_id,
                'name' => $order->store->name,
                'status' => $order->store->status->value,
                'status_label' => $order->store->status->label(),
                'status_tone' => $order->store->status->tone(),
            ],
            'status' => $order->source_status,
            'status_label' => ExternalOrderStatusDisplay::label($order->source_status),
            'status_tone' => ExternalOrderStatusDisplay::tone($order->source_status),
            'payment_status' => $order->payment_status,
            'payment_label' => ExternalOrderStatusDisplay::label($order->payment_status),
            'payment_tone' => ExternalOrderStatusDisplay::tone($order->payment_status),
            'payment_method' => $order->payment_method,
            'placed_at' => $order->placed_at->toIso8601String(),
            'source_updated_at' => $order->source_updated_at?->toIso8601String(),
            'last_synced_at' => $order->last_synced_at->toIso8601String(),
            'customer' => [
                'id' => $customer?->public_id,
                'name' => $order->customer['name'] ?? null,
                'phone' => $order->customer['phone'] ?? null,
                'email' => $order->customer['email'] ?? null,
            ],
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'note' => $order->note,
            'cancel_reason' => $order->cancel_reason,
            'coupon_code' => $order->coupon_code,
            'totals' => [
                'subtotal' => $order->subtotal->jsonSerialize(),
                'discount' => $order->discount->jsonSerialize(),
                'shipping' => $order->shipping_total->jsonSerialize(),
                'tax' => $order->tax->jsonSerialize(),
                'total' => $order->total->jsonSerialize(),
                'paid' => $order->paid_total?->jsonSerialize(),
            ],
            'lines' => $order->items->map(fn (ExternalOrderItem $item) => [
                'line' => $item->line_number,
                'name' => $item->name,
                'sku' => $item->sku,
                'variant' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->jsonSerialize(),
                'total' => $item->line_total->jsonSerialize(),
            ])->all(),
        ];
    }

    /**
     * @return array{search: string|null, source: string|null, store: string|null, status: string|null, payment: string|null, from: string|null, to: string|null, sort: string, direction: string}
     */
    protected function filters(Request $request): array
    {
        $text = fn (string $key, int $max) => ($value = trim($request->string($key)->toString())) === '' ? null : mb_substr($value, 0, $max);
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string($key)->toString()) === 1
            ? $request->string($key)->toString()
            : null;

        $source = $request->string('source')->toString();
        $sort = $request->string('sort')->toString();

        return [
            'search' => $text('search', 120),
            'source' => in_array($source, BusinessOrderIndex::SOURCES, true) ? $source : null,
            'store' => $text('store', 64),
            'status' => $text('status', 40),
            'payment' => $text('payment', 40),
            'from' => $date('from'),
            'to' => $date('to'),
            'sort' => array_key_exists($sort, BusinessOrderIndex::SORTS) ? $sort : 'placed_at',
            'direction' => $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * A value a spreadsheet will not run: anything starting like a formula is
     * prefixed so it is read as text.
     */
    protected function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    protected function readerAccount(Request $request): BusinessAccount
    {
        $account = $this->businessAccountFor($request);

        $user = $request->user();

        abort_unless($user instanceof User && ($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false), 403);

        return $account;
    }
}
