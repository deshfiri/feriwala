<?php

namespace App\Services;

use App\Erp\Client;
use App\Erp\ErpException;

/**
 * What the ERP says a basket costs: the figures the cart and the checkout form
 * show. Nothing is computed here; the ERP's answer is reshaped, never recalculated.
 */
final class BasketQuote
{
    public function __construct(
        private readonly Client $erp,
        private readonly Site $site,
    ) {}

    /**
     * Price a basket. The ERP is asked for a payment method it will take, so the
     * quote answers about the cart and not about a method nobody chose.
     *
     * @param  list<array{sku: string, quantity: int}>  $items
     * @return array<string, mixed>
     *
     * @throws ErpException
     */
    public function for(array $items): array
    {
        $methods = (array) $this->site->get()['payment_methods'];
        $response = $this->erp->post('/checkout/quote', [
            'items' => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']], $items),
            'payment_method' => (string) ($methods[0] ?? 'online'),
        ]);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $lines = [];

        foreach ((array) ($data['items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $availability = is_array($line['availability'] ?? null) ? $line['availability'] : [];
            $lines[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => is_array($line['unit_price'] ?? null) ? $line['unit_price'] : null,
                'line_total' => is_array($line['line_total'] ?? null) ? $line['line_total'] : null,
                'in_stock' => (bool) ($availability['in_stock'] ?? true),
                'reservable' => (bool) ($availability['reservable'] ?? true),
            ];
        }

        return [
            'items' => $lines,
            'subtotal' => is_array($data['subtotal'] ?? null) ? $data['subtotal'] : null,
            'discount' => is_array($data['discount'] ?? null) ? $data['discount'] : null,
            'shipping' => is_array($data['shipping'] ?? null) ? $data['shipping'] : null,
            'tax' => is_array($data['tax'] ?? null) ? $data['tax'] : null,
            'grand_total' => is_array($data['grand_total'] ?? null) ? $data['grand_total'] : null,
        ];
    }
}
