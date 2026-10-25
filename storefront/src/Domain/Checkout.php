<?php

declare(strict_types=1);

namespace Storefront\Domain;

use Storefront\App;
use Storefront\Erp\ErpException;
use Storefront\Erp\Signature;
use Storefront\Support\Db;
use Storefront\Support\Env;

/**
 * From a basket to an order the ERP has accepted (contract §6.0, §6.1).
 *
 * The storefront decides nothing about money. A *quote* asks the ERP what the
 * cart costs; the order is then submitted with exactly those figures as the
 * storefront's claim, and the ERP prices it again and refuses any difference
 * with `price_mismatch` (§6.1.1). When that happens the customer is shown the
 * revised total and must confirm it again, under a **new** idempotency key — a
 * price is never silently accepted on their behalf.
 */
final class Checkout
{
    public const DRAFT = 'draft';

    public const PLACING = 'placing';

    public const PLACED = 'placed';

    /**
     * Check the details a customer typed.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $methods  what the shop takes
     * @param  list<string>  $gateways  names of the gateways behind "online"; empty when the ERP names none
     * @return array{errors: array<string, string>, data: array<string, string>}
     */
    public static function validate(array $input, array $methods, array $gateways = []): array
    {
        $get = static fn (string $key, int $max = 255): string => mb_substr(trim((string) (is_scalar($input[$key] ?? null) ? $input[$key] : '')), 0, $max);

        $data = [
            'name' => $get('name', 160),
            'phone' => $get('phone', 32),
            'email' => $get('email', 254),
            'line1' => $get('line1'),
            'line2' => $get('line2'),
            'city' => $get('city', 120),
            'district' => $get('district', 120),
            'postcode' => $get('postcode', 20),
            'area_id' => $get('area_id', 80),
            'note' => $get('note', 1000),
            'payment_method' => $get('payment_method', 20),
            'gateway' => $get('gateway', 40),
        ];

        // Only an online payment goes through a gateway; the one chosen must be
        // one the ERP offered, and a lone gateway needs no choosing.
        if ($data['payment_method'] !== 'online' || $gateways === []) {
            $data['gateway'] = '';
        } elseif ($data['gateway'] === '' && count($gateways) === 1) {
            $data['gateway'] = $gateways[0];
        }

        $errors = [];

        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'checkout.error.name';
        }

        $digits = preg_replace('/\D/', '', $data['phone']) ?? '';

        if (preg_match('/^[0-9+\-\s()]+$/', $data['phone']) !== 1 || strlen($digits) < 10 || strlen($digits) > 15) {
            $errors['phone'] = 'checkout.error.phone';
        }

        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'checkout.error.email';
        }

        if ($data['line1'] === '') {
            $errors['line1'] = 'checkout.error.address';
        }

        if ($data['city'] === '') {
            $errors['city'] = 'checkout.error.city';
        }

        if (! in_array($data['payment_method'], $methods, true)) {
            $errors['payment_method'] = 'checkout.error.payment_method';
        } elseif ($data['payment_method'] === 'online' && $gateways !== [] && ! in_array($data['gateway'], $gateways, true)) {
            $errors['payment_method'] = 'checkout.error.gateway';
        }

        return ['errors' => $errors, 'data' => $data];
    }

    /**
     * Start a checkout from the basket and the details given.
     *
     * @param  array<string, string>  $data  validated
     * @return array<string, mixed> the stored checkout
     */
    public static function create(Cart $cart, array $data): array
    {
        $items = array_map(static fn (array $line): array => [
            'sku' => $line['sku'],
            'quantity' => $line['quantity'],
            'name' => $line['name'],
            'variant' => $line['variant'],
            'image' => $line['image'],
            'slug' => $line['slug'],
        ], $cart->items());

        if ($items === []) {
            throw new \DomainException('empty_cart');
        }

        $shipping = [
            'line1' => $data['line1'],
            'line2' => $data['line2'] !== '' ? $data['line2'] : null,
            'city' => $data['city'],
            'district' => $data['district'] !== '' ? $data['district'] : null,
            'postcode' => $data['postcode'] !== '' ? $data['postcode'] : null,
            'country' => 'BD',
        ];

        if ($data['area_id'] !== '') {
            $shipping['area_id'] = $data['area_id'];
        }

        $now = Db::now();
        $token = bin2hex(random_bytes(20));

        Db::exec(
            'INSERT INTO checkouts (token, cart_id, idempotency_key, status, customer_json, shipping_json, items_json,
                                    payment_method, gateway, customer_note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $token,
                $cart->id(),
                Signature::nonce(),
                self::DRAFT,
                self::json(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] !== '' ? $data['email'] : null]),
                self::json($shipping),
                self::json($items),
                $data['payment_method'],
                ($data['gateway'] ?? '') !== '' ? $data['gateway'] : null,
                $data['note'] !== ''? $data['note'] : null,
                $now,
                $now,
            ],
        );

        $id = Db::lastId();

        // A counter alone would repeat itself if this database were ever restored
        // from a backup, and the ERP refuses a reference it has already seen. A
        // short random tail makes a restored counter harmless.
        Db::exec(
            'UPDATE checkouts SET reference = ? WHERE id = ?',
            [sprintf('SF-%s-%06d-%s', gmdate('Y'), $id, strtoupper(bin2hex(random_bytes(2)))), $id],
        );

        return (array) self::find($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{40}$/', $token) !== 1) {
            return null;
        }

        $row = Db::one('SELECT * FROM checkouts WHERE token = ?', [$token]);

        if ($row === null) {
            return null;
        }

        foreach (['customer_json', 'shipping_json', 'billing_json', 'items_json', 'quote_json'] as $column) {
            $row[$column] = $row[$column] === null ? null : json_decode((string) $row[$column], true);
        }

        return $row;
    }

    /**
     * Ask the ERP what this cart costs now, and keep the answer.
     *
     * @param  array<string, mixed>  $checkout
     * @return array<string, mixed> the normalised quote
     *
     * @throws ErpException
     */
    public static function quote(array $checkout): array
    {
        $shipping = (array) $checkout['shipping_json'];

        $response = App::erp()->post('/checkout/quote', [
            'items' => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => (int) $line['quantity']], (array) $checkout['items_json']),
            'payment_method' => (string) $checkout['payment_method'],
            'delivery' => ['area_id' => $shipping['area_id'] ?? null],
        ]);

        $quote = self::normaliseQuote(is_array($response['data'] ?? null) ? $response['data'] : []);

        self::saveQuote((int) $checkout['id'], $quote);

        return $quote;
    }

    /**
     * Submit the order with the figures the customer was shown.
     *
     * @param  array<string, mixed>  $checkout
     * @return array{outcome: 'placed', order: array<string, mixed>}|array{outcome: 'repriced', quote: array<string, mixed>}
     *
     * @throws ErpException any refusal other than a price change
     */
    public static function place(array $checkout, ?string $returnUrl): array
    {
        $quote = $checkout['quote_json'];

        if (! is_array($quote) || ($quote['items'] ?? []) === []) {
            throw new \DomainException('not_quoted');
        }

        // A request that died mid-placement must not wedge the checkout for
        // ever: a claim older than two minutes is taken as abandoned and may be
        // retried under the same key, which the ERP answers idempotently.
        $claimed = Db::exec(
            "UPDATE checkouts SET status = 'placing', updated_at = ?
             WHERE id = ? AND (status = 'draft' OR (status = 'placing' AND updated_at < ?))",
            [Db::now(), $checkout['id'], gmdate('Y-m-d H:i:s', time() - 120)],
        );

        if ($claimed === 0) {
            throw new \DomainException('already_placing');
        }

        try {
            $response = App::erp()->post('/orders', self::orderBody($checkout, $quote, $returnUrl), (string) $checkout['idempotency_key']);
        } catch (ErpException $exception) {
            // Back to a draft either way: a price change is reconfirmed under a
            // new key, and a refusal or an outage is retried under the same one
            // (so an order the ERP did take is never taken twice).
            if ($exception->is('price_mismatch')) {
                $revised = self::fromAuthoritative($exception->details, (string) ($quote['currency'] ?? 'BDT'), $quote);

                Db::exec(
                    "UPDATE checkouts SET status = 'draft', idempotency_key = ?, quote_json = ?, updated_at = ? WHERE id = ?",
                    [Signature::nonce(), self::json($revised), Db::now(), $checkout['id']],
                );

                return ['outcome' => 'repriced', 'quote' => $revised];
            }

            Db::exec("UPDATE checkouts SET status = 'draft', updated_at = ? WHERE id = ?", [Db::now(), $checkout['id']]);

            throw $exception;
        }

        $order = is_array($response['data'] ?? null) ? $response['data'] : $response;

        Db::exec(
            "UPDATE checkouts SET status = 'placed', placed_order_id = ?, updated_at = ? WHERE id = ?",
            [(string) ($order['id'] ?? ''), Db::now(), $checkout['id']],
        );

        return ['outcome' => 'placed', 'order' => $order];
    }

    /**
     * Whether a return URL is one the ERP will accept: https, standard port.
     * Anything else is not sent, and the customer lands on the shop's home.
     */
    public static function returnUrl(): ?string
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');

        return str_starts_with($base, 'https://') ? $base.'/payment/return' : null;
    }

    /**
     * @param  array<string, mixed>  $checkout
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private static function orderBody(array $checkout, array $quote, ?string $returnUrl): array
    {
        $customer = (array) $checkout['customer_json'];
        $currency = (string) ($quote['currency'] ?? 'BDT');

        $payment = ['method' => (string) $checkout['payment_method']];

        if ($returnUrl !== null && $payment['method'] === 'online') {
            $payment['return_url'] = $returnUrl;
        }

        if ($payment['method'] === 'online' && is_string($checkout['gateway'] ?? null) && $checkout['gateway'] !== '') {
            $payment['gateway'] = $checkout['gateway'];
        }

        $body = [
            'storefront_order_reference' => (string) $checkout['reference'],
            'placed_at' => gmdate('c'),
            'customer' => [
                'name' => (string) $customer['name'],
                'phone' => (string) $customer['phone'],
                'email' => $customer['email'] ?? null,
                'is_guest' => true,
            ],
            'shipping_address' => (array) $checkout['shipping_json'],
            'items' => array_map(static fn (array $line): array => [
                'sku' => (string) $line['sku'],
                'quantity' => (int) $line['quantity'],
                'unit_price' => self::claim($line['unit_price'] ?? null, $currency),
            ], (array) $quote['items']),
            'totals' => [
                'subtotal' => self::claim($quote['subtotal'] ?? null, $currency),
                'discount' => self::claim($quote['discount'] ?? null, $currency),
                'shipping' => self::claim($quote['shipping'] ?? null, $currency),
                'tax' => self::claim($quote['tax'] ?? null, $currency),
                'grand_total' => self::claim($quote['grand_total'] ?? null, $currency),
            ],
            'payment' => $payment,
            'coupon_code' => null,
            'customer_note' => $checkout['customer_note'],
        ];

        return $body;
    }

    /**
     * A money claim in the one shape the contract wants for anything new:
     * `{amount, currency}` — flat Taka, never `minor_units`.
     *
     * @return array{amount: string, currency: string}
     */
    private static function claim(mixed $money, string $currency): array
    {
        $amount = is_array($money) ? (string) ($money['amount'] ?? '0') : '0';

        return ['amount' => $amount, 'currency' => is_array($money) && is_string($money['currency'] ?? null) ? $money['currency'] : $currency];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normaliseQuote(array $data): array
    {
        $money = static fn (mixed $value): ?array => is_array($value) && isset($value['amount'])
            ? ['amount' => (string) $value['amount'], 'currency' => (string) ($value['currency'] ?? 'BDT'), 'formatted' => is_string($value['formatted'] ?? null) ? $value['formatted'] : null]
            : null;

        $items = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $availability = is_array($line['availability'] ?? null) ? $line['availability'] : [];

            $items[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => $money($line['unit_price'] ?? null),
                'line_total' => $money($line['line_total'] ?? null),
                'in_stock' => (bool) ($availability['in_stock'] ?? true),
                'reservable' => (bool) ($availability['reservable'] ?? true),
            ];
        }

        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $cod = is_array($payment['cod'] ?? null) ? $payment['cod'] : [];

        return [
            'currency' => (string) ($data['currency'] ?? 'BDT'),
            'items' => $items,
            'subtotal' => $money($data['subtotal'] ?? null),
            'discount' => $money($data['discount'] ?? null),
            'shipping' => $money($data['shipping'] ?? null),
            'tax' => $money($data['tax'] ?? null),
            'tax_included' => $money($data['tax_included'] ?? null),
            'grand_total' => $money($data['grand_total'] ?? null),
            'cod_eligible' => (bool) ($cod['eligible'] ?? true),
            'cod_maximum' => $money($cod['maximum'] ?? null),
            'quoted_at' => is_string($data['quoted_at'] ?? null) ? $data['quoted_at'] : gmdate('c'),
            'valid_until' => is_string($data['valid_until'] ?? null) ? $data['valid_until'] : null,
        ];
    }

    /**
     * The figures a `price_mismatch` error carries, as a quote.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private static function fromAuthoritative(array $details, string $currency, array $previous): array
    {
        $authoritative = is_array($details['authoritative'] ?? null) ? $details['authoritative'] : [];
        $quote = self::normaliseQuote(['currency' => $currency] + $authoritative);

        // What the revision does not carry (the COD terms, availability) is kept.
        foreach (['cod_eligible', 'cod_maximum', 'tax_included'] as $keep) {
            if ($quote[$keep] === null || $quote[$keep] === true) {
                $quote[$keep] = $previous[$keep] ?? $quote[$keep];
            }
        }

        $quote['repriced'] = true;

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private static function saveQuote(int $id, array $quote): void
    {
        Db::exec('UPDATE checkouts SET quote_json = ?, updated_at = ? WHERE id = ?', [self::json($quote), Db::now(), $id]);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function prune(int $draftDays = 14): int
    {
        return Db::exec(
            "DELETE FROM checkouts WHERE status <> 'placed' AND updated_at < ?",
            [gmdate('Y-m-d H:i:s', time() - $draftDays * 86400)],
        );
    }
}
