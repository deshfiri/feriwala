<?php

namespace App\Services;

use App\Erp\Client;
use App\Erp\ErpException;
use App\Erp\Signature;
use App\Models\Checkout;
use DomainException;

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
final class Checkouts
{
    public function __construct(private readonly Client $erp) {}

    /**
     * Start a checkout from the basket and the details given.
     *
     * @param  array<string, string>  $data  validated by CheckoutDetailsRequest
     *
     * @throws DomainException when the basket is empty
     */
    public function create(Basket $basket, array $data): Checkout
    {
        $items = array_map(static fn (array $line): array => [
            'sku' => $line['sku'],
            'quantity' => $line['quantity'],
            'name' => $line['name'],
            'variant' => $line['variant'],
            'image' => $line['image'],
            'slug' => $line['slug'],
        ], $basket->items());

        if ($items === []) {
            throw new DomainException('empty_cart');
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

        $checkout = Checkout::query()->create([
            'token' => bin2hex(random_bytes(20)),
            'cart_id' => $basket->id(),
            'idempotency_key' => Signature::nonce(),
            'status' => Checkout::DRAFT,
            'customer_json' => ['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] !== '' ? $data['email'] : null],
            'shipping_json' => $shipping,
            'items_json' => $items,
            'payment_method' => $data['payment_method'],
            'gateway' => ($data['gateway'] ?? '') !== '' ? $data['gateway'] : null,
            'customer_note' => $data['note'] !== '' ? $data['note'] : null,
        ]);

        // A counter alone would repeat itself if this database were ever restored
        // from a backup, and the ERP refuses a reference it has already seen. A
        // short random tail makes a restored counter harmless.
        $checkout->update(['reference' => sprintf('SF-%s-%06d-%s', gmdate('Y'), $checkout->id, strtoupper(bin2hex(random_bytes(2))))]);

        return $checkout;
    }

    public function find(string $token): ?Checkout
    {
        if (preg_match('/^[0-9a-f]{40}$/', $token) !== 1) {
            return null;
        }

        return Checkout::query()->where('token', $token)->first();
    }

    /**
     * Ask the ERP what this cart costs now, and keep the answer.
     *
     * @return array<string, mixed> the normalised quote
     *
     * @throws ErpException
     */
    public function quote(Checkout $checkout): array
    {
        $shipping = $checkout->shipping_json;

        $response = $this->erp->post('/checkout/quote', [
            'items' => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => (int) $line['quantity']], $checkout->items_json),
            'payment_method' => $checkout->payment_method,
            'delivery' => ['area_id' => $shipping['area_id'] ?? null],
        ]);

        $quote = self::normaliseQuote(is_array($response['data'] ?? null) ? $response['data'] : []);

        $checkout->update(['quote_json' => $quote]);

        return $quote;
    }

    /**
     * Submit the order with the figures the customer was shown.
     *
     * @return array{outcome: 'placed', order: array<string, mixed>}|array{outcome: 'repriced', quote: array<string, mixed>}
     *
     * @throws ErpException any refusal other than a price change
     * @throws DomainException `not_quoted` or `already_placing`
     */
    public function place(Checkout $checkout, ?string $returnUrl): array
    {
        $quote = $checkout->quote_json;

        if (! is_array($quote) || ($quote['items'] ?? []) === []) {
            throw new DomainException('not_quoted');
        }

        // A request that died mid-placement must not wedge the checkout for
        // ever: a claim older than two minutes is taken as abandoned and may be
        // retried under the same key, which the ERP answers idempotently.
        $claimed = Checkout::query()
            ->whereKey($checkout->id)
            ->where(fn ($query) => $query
                ->where('status', Checkout::DRAFT)
                ->orWhere(fn ($query) => $query->where('status', Checkout::PLACING)->where('updated_at', '<', now()->subSeconds(120))))
            ->update(['status' => Checkout::PLACING, 'updated_at' => now()]);

        if ($claimed === 0) {
            throw new DomainException('already_placing');
        }

        // The claim was a bare UPDATE; reload so later saves see the status it set.
        $checkout->refresh();

        try {
            $response = $this->erp->post('/orders', self::orderBody($checkout, $quote, $returnUrl), $checkout->idempotency_key);
        } catch (ErpException $exception) {
            // Back to a draft either way: a price change is reconfirmed under a
            // new key, and a refusal or an outage is retried under the same one
            // (so an order the ERP did take is never taken twice).
            if ($exception->is('price_mismatch')) {
                $revised = self::fromAuthoritative($exception->details, (string) ($quote['currency'] ?? 'BDT'), $quote);

                $checkout->forceFill([
                    'status' => Checkout::DRAFT,
                    'idempotency_key' => Signature::nonce(),
                    'quote_json' => $revised,
                ])->save();

                return ['outcome' => 'repriced', 'quote' => $revised];
            }

            $checkout->forceFill(['status' => Checkout::DRAFT])->save();

            throw $exception;
        }

        $order = is_array($response['data'] ?? null) ? $response['data'] : $response;

        $checkout->forceFill(['status' => Checkout::PLACED, 'placed_order_id' => (string) ($order['id'] ?? '')])->save();

        return ['outcome' => 'placed', 'order' => $order];
    }

    /**
     * Whether a return URL is one the ERP will accept: https, standard port.
     * Anything else is not sent, and the customer lands on the shop's home.
     */
    public static function returnUrl(): ?string
    {
        $base = rtrim((string) config('app.url'), '/');

        return str_starts_with($base, 'https://') ? $base.'/payment/return' : null;
    }

    public static function prune(int $draftDays = 14): int
    {
        return Checkout::query()
            ->where('status', '!=', Checkout::PLACED)
            ->where('updated_at', '<', now()->subDays($draftDays))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private static function orderBody(Checkout $checkout, array $quote, ?string $returnUrl): array
    {
        $customer = $checkout->customer_json;
        $currency = (string) ($quote['currency'] ?? 'BDT');

        $payment = ['method' => $checkout->payment_method];

        if ($returnUrl !== null && $payment['method'] === 'online') {
            $payment['return_url'] = $returnUrl;
        }

        if ($payment['method'] === 'online' && is_string($checkout->gateway) && $checkout->gateway !== '') {
            $payment['gateway'] = $checkout->gateway;
        }

        return [
            'storefront_order_reference' => (string) $checkout->reference,
            'placed_at' => gmdate('c'),
            'customer' => [
                'name' => (string) $customer['name'],
                'phone' => (string) $customer['phone'],
                'email' => $customer['email'] ?? null,
                'is_guest' => true,
            ],
            'shipping_address' => $checkout->shipping_json,
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
            'customer_note' => $checkout->customer_note,
        ];
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
    public static function normaliseQuote(array $data): array
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
}
