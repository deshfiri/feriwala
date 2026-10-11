<?php

namespace App\Integrations\Store\Drivers;

use App\Integrations\Store\Contracts\StorePlatformDriver;
use App\Integrations\Store\Data\StoreEndpoint;
use App\Integrations\Store\Data\StoreOrder;
use App\Integrations\Store\Data\StoreOrderLine;
use App\Integrations\Store\Data\StoreOrderPage;
use App\Integrations\Store\Data\StoreWebhook;
use App\Integrations\Store\Exceptions\StorePlatformException;
use App\Integrations\Store\Exceptions\UnreadableStoreOrder;
use App\Integrations\Store\Support\ExactJson;
use App\Integrations\Store\Support\OutboundAddressGuard;
use App\Integrations\Store\Support\StoreAddress;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

/**
 * DFCOMMERCE, the storefront platform the Deshfiri warehouse OMS already
 * integrates with (D29).
 *
 * The contract is the one that integration uses in production, not a guess:
 *
 *   - `GET {base}/api/v1/orders?since_id=&updated_since=&limit=`, answered with
 *     `{ok, data: [order…], paging: {next_since_id}}`;
 *   - credentials in `X-API-Key` and `X-API-Secret` on every call;
 *   - notifications signed `X-Webhook-Signature: sha256=<hmac of the raw
 *     body>`, identified by `X-Webhook-Event-Id`.
 *
 * DFCOMMERCE has no ping endpoint, so a connection is verified by reading one
 * order — which also proves the key carries `orders.read`, the only scope this
 * integration needs. Nothing here writes to the store.
 *
 * Every call goes to an address the {@see OutboundAddressGuard} checked, with
 * redirects off and short timeouts. A failure becomes a
 * {@see StorePlatformException} whose message is written here, never copied
 * from what the store answered.
 */
class DfcommerceDriver implements StorePlatformDriver
{
    public const PAGE_SIZE = 100;

    /** The events the setup instructions ask the partner to send. */
    public const WEBHOOK_EVENTS = ['order.placed', 'order.updated', 'order.cancelled', 'order.refunded', 'payment.received'];

    private const ORDER_EVENTS = ['order.placed', 'order.updated', 'order.cancelled', 'order.refunded'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly OutboundAddressGuard $guard,
    ) {}

    public function verify(StoreEndpoint $endpoint): void
    {
        $this->get($endpoint, 'orders', ['limit' => 1]);
    }

    public function fetchOrders(StoreEndpoint $endpoint, int $sinceId, ?CarbonImmutable $updatedSince = null): StoreOrderPage
    {
        $query = ['since_id' => $sinceId, 'limit' => self::PAGE_SIZE];

        if ($updatedSince !== null) {
            // A plain UTC date-time: the storefront compares it as text, and
            // an ISO-8601 string with an offset silently matches nothing.
            $query['updated_since'] = $updatedSince->utc()->format('Y-m-d H:i:s');
        }

        $body = $this->get($endpoint, 'orders', $query);
        $rows = $body['data'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw StorePlatformException::unreadable();
        }

        $orders = [];
        $unreadable = [];
        $highest = $sinceId;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $unreadable[] = "row {$index}: not an order";

                continue;
            }

            $id = $this->digits($row['id'] ?? null);

            if ($id !== null) {
                $highest = max($highest, (int) $id);
            }

            try {
                $orders[] = $this->order($row);
            } catch (UnreadableStoreOrder $refused) {
                $unreadable[] = 'order '.($id ?? "row {$index}").': '.$refused->getMessage();
            }
        }

        $next = $this->digits($body['paging']['next_since_id'] ?? null);

        return new StoreOrderPage(
            orders: $orders,
            nextCursor: $next === null ? null : (int) $next,
            highestId: $highest,
            unreadable: $unreadable,
        );
    }

    public function signatureIsValid(string $rawBody, array $headers, string $secret): bool
    {
        $given = (string) ($headers['x-webhook-signature'] ?? '');

        return $given !== '' && hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $given);
    }

    public function webhookEventId(array $headers): ?string
    {
        $id = trim((string) ($headers['x-webhook-event-id'] ?? ''));

        return $id === '' || strlen($id) > 100 ? null : $id;
    }

    public function readWebhook(array $payload): StoreWebhook
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        if (in_array($event, self::ORDER_EVENTS, true)) {
            return new StoreWebhook($event, order: $this->order($data));
        }

        if ($event === 'payment.received') {
            $number = $this->text($data['order_number'] ?? null, 64);

            if ($number === null) {
                throw new UnreadableStoreOrder('payment without an order number');
            }

            // The contract names `amount`; read `paid_total` too, and never
            // invent one when neither is there.
            $amount = $data['amount'] ?? $data['paid_total'] ?? null;

            return new StoreWebhook($event, payment: [
                'order_number' => $number,
                'amount' => $amount === null ? null : $this->amount($amount, 'amount'),
                'method' => $this->text($data['method'] ?? null, 40),
                'received_at' => $this->time($data['received_at'] ?? null),
            ]);
        }

        return new StoreWebhook($event);
    }

    public function webhookEvents(): array
    {
        return self::WEBHOOK_EVENTS;
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     *
     * @throws StorePlatformException
     */
    protected function get(StoreEndpoint $endpoint, string $path, array $query): array
    {
        $address = StoreAddress::parse($endpoint->baseUrl) ?? throw StorePlatformException::addressRefused();
        $ip = $this->guard->addressFor($address->host);
        $port = (int) (parse_url($address->baseUrl, PHP_URL_PORT) ?? 443);

        try {
            $response = $this->http
                ->withHeaders([
                    'X-API-Key' => $endpoint->apiKey,
                    'X-API-Secret' => $endpoint->apiSecret,
                    'User-Agent' => 'Feriwala/1.0',
                ])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(15)
                ->withOptions([
                    'allow_redirects' => false,
                    // Connect to the address that was checked, not to whatever
                    // the name resolves to a moment later.
                    'curl' => [CURLOPT_RESOLVE => ["{$address->host}:{$port}:{$ip}"]],
                ])
                ->retry(2, 300, fn (Throwable $exception) => $exception instanceof ConnectionException, throw: false)
                ->get($address->baseUrl.'/api/v1/'.$path, $query);
        } catch (ConnectionException) {
            throw StorePlatformException::unreachable();
        }

        return $this->body($response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StorePlatformException
     */
    protected function body(Response $response): array
    {
        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw StorePlatformException::credentialsRefused($status);
        }

        if ($status === 404) {
            throw StorePlatformException::notFound();
        }

        if ($status === 429) {
            throw StorePlatformException::rateLimited();
        }

        if ($status < 200 || $status >= 300) {
            throw StorePlatformException::failed($status);
        }

        try {
            $body = ExactJson::decode($response->body());
        } catch (JsonException) {
            throw StorePlatformException::unreadable();
        }

        if (! is_array($body) || ($body['ok'] ?? true) === false) {
            throw StorePlatformException::unreadable();
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws UnreadableStoreOrder
     */
    protected function order(array $row): StoreOrder
    {
        $externalId = $this->digits($row['id'] ?? null) ?? throw new UnreadableStoreOrder('missing id');
        $number = $this->text($row['order_number'] ?? null, 64) ?? throw new UnreadableStoreOrder('missing order number');
        $status = $this->text($row['status'] ?? null, 40) ?? throw new UnreadableStoreOrder('missing status');
        $placedAt = $this->time($row['placed_at'] ?? null) ?? throw new UnreadableStoreOrder('missing placed_at');

        $currency = Currency::tryFrom(strtoupper((string) ($row['currency'] ?? 'BDT')));

        if ($currency !== Currency::BDT) {
            throw new UnreadableStoreOrder('unsupported currency');
        }

        // Nested `totals`, or the older flat fields — both are in use.
        $totals = is_array($row['totals'] ?? null) ? $row['totals'] : [];
        $pick = fn (string ...$keys) => $this->first($totals, $row, $keys);

        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        $shipping = is_array($row['shipping'] ?? null) ? $row['shipping'] : null;
        $billing = is_array($row['billing'] ?? null) ? $row['billing'] : null;
        $items = $row['items'] ?? [];

        if (! is_array($items) || ! array_is_list($items)) {
            throw new UnreadableStoreOrder('items are not a list');
        }

        $paid = $pick('paid', 'paid_total');

        return new StoreOrder(
            externalId: $externalId,
            orderNumber: $number,
            status: strtolower($status),
            paymentStatus: $this->lower($this->text($row['payment_status'] ?? null, 40)),
            paymentMethod: $this->lower($this->text($row['payment_method'] ?? null, 40)),
            currencyCode: $currency->value,
            subtotal: $this->amount($pick('subtotal') ?? '0', 'subtotal'),
            discount: $this->amount($pick('discount') ?? '0', 'discount'),
            couponCode: $this->text($pick('coupon_code'), 64),
            shippingTotal: $this->amount($pick('shipping', 'shipping_total') ?? '0', 'shipping'),
            tax: $this->amount($pick('tax') ?? '0', 'tax'),
            total: $this->amount($pick('grand', 'grand_total', 'total') ?? throw new UnreadableStoreOrder('missing total'), 'total'),
            paidTotal: $paid === null ? null : $this->amount($paid, 'paid'),
            customer: [
                'external_id' => $this->digits($row['customer_id'] ?? $customer['id'] ?? null),
                'name' => $this->text($customer['name'] ?? $row['customer_name'] ?? null, 160),
                'phone' => $this->text($customer['phone'] ?? $row['customer_phone'] ?? null, 32),
                'email' => $this->text($customer['email'] ?? $row['customer_email'] ?? null, 191),
            ],
            shippingAddress: $shipping === null ? null : $this->address($shipping),
            billingAddress: $billing === null ? null : $this->address($billing),
            note: $this->text($row['note'] ?? null, 2000),
            cancelReason: $this->text($row['cancel_reason'] ?? null, 255),
            placedAt: $placedAt,
            updatedAt: $this->time($row['updated_at'] ?? null),
            lines: array_map(fn (mixed $item) => $this->line($item), $items),
        );
    }

    /**
     * @throws UnreadableStoreOrder
     */
    protected function line(mixed $item): StoreOrderLine
    {
        if (! is_array($item)) {
            throw new UnreadableStoreOrder('an item is not an object');
        }

        $quantity = $this->digits($item['qty'] ?? $item['quantity'] ?? null);

        if ($quantity === null || (int) $quantity < 1) {
            throw new UnreadableStoreOrder('an item has no quantity');
        }

        $variant = $item['variant'] ?? $item['variant_label'] ?? null;

        if (is_array($variant)) {
            $variant = implode(' / ', array_filter(array_map(
                fn (mixed $value) => is_string($value) ? $value : null,
                $variant,
            )));
        }

        return new StoreOrderLine(
            name: $this->text($item['name'] ?? null, 255) ?? throw new UnreadableStoreOrder('an item has no name'),
            quantity: (int) $quantity,
            unitPrice: $this->amount($item['unit_price'] ?? throw new UnreadableStoreOrder('an item has no unit price'), 'unit_price'),
            lineTotal: $this->amount($item['line_total'] ?? throw new UnreadableStoreOrder('an item has no line total'), 'line_total'),
            sku: $this->text($item['sku'] ?? null, 120),
            externalProductId: $this->digits($item['product_id'] ?? null),
            variantLabel: $this->text(is_string($variant) ? $variant : null, 255),
        );
    }

    /**
     * @param  array<array-key, mixed>  $address
     * @return array<string, string|null>
     */
    protected function address(array $address): array
    {
        return [
            'name' => $this->text($address['name'] ?? null, 160),
            'phone' => $this->text($address['phone'] ?? null, 32),
            'line' => $this->text($address['address'] ?? $address['address_line'] ?? null, 255),
            'area' => $this->text($address['area'] ?? null, 120),
            'city' => $this->text($address['city'] ?? null, 120),
            'district' => $this->text($address['district'] ?? null, 120),
            'postcode' => $this->text($address['postcode'] ?? null, 20),
            'zone' => $this->text($address['zone'] ?? null, 40),
        ];
    }

    /**
     * An exact Taka amount from the store's text. Trailing zeros past two
     * places are accepted; real extra precision is refused, never rounded.
     *
     * @throws UnreadableStoreOrder
     */
    protected function amount(mixed $value, string $field): Money
    {
        if (! is_string($value) || preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $value, $parts) !== 1) {
            throw new UnreadableStoreOrder("{$field} is not an amount");
        }

        $fraction = rtrim($parts[2] ?? '', '0');

        if (strlen($fraction) > 2) {
            throw new UnreadableStoreOrder("{$field} has more precision than Taka");
        }

        return Money::fromDecimal($fraction === '' ? $parts[1] : "{$parts[1]}.{$fraction}", Currency::BDT);
    }

    /**
     * @param  array<array-key, mixed>  $nested
     * @param  array<array-key, mixed>  $flat
     * @param  array<array-key, string>  $keys
     */
    protected function first(array $nested, array $flat, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $nested) && $nested[$key] !== null) {
                return $nested[$key];
            }
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $flat) && $flat[$key] !== null) {
                return $flat[$key];
            }
        }

        return null;
    }

    protected function digits(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,20}$/', $value) === 1 ? ltrim($value, '0') ?: '0' : null;
    }

    protected function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function lower(?string $value): ?string
    {
        return $value === null ? null : strtolower($value);
    }

    protected function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
