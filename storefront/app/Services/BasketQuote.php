<?php

namespace App\Services;

use App\Erp\Client;
use App\Erp\ErpException;

/**
 * What the ERP says a basket costs: the figures the cart and the checkout form
 * show. Nothing is computed here; the ERP's answer is reshaped, never recalculated.
 *
 * The delivery charge depends on where the order goes (D37): with an area the
 * ERP prices it, Inside or Outside Dhaka; without one it prices the goods and
 * says an area is needed, and `shipping` and `grand_total` come back empty.
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
     * @param  string|null  $areaId  a delivery area id from the ERP's directory
     * @return array<string, mixed> the quote, normalised as {@see Checkouts::normaliseQuote()} does
     *
     * @throws ErpException
     */
    public function for(array $items, ?string $areaId = null): array
    {
        $methods = (array) $this->site->get()['payment_methods'];
        $body = [
            'items' => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']], $items),
            'payment_method' => (string) ($methods[0] ?? 'online'),
        ];

        if ($areaId !== null && $areaId !== '') {
            $body['delivery'] = ['area_id' => $areaId];
        }

        $response = $this->erp->post('/checkout/quote', $body);

        return Checkouts::normaliseQuote(is_array($response['data'] ?? null) ? $response['data'] : []);
    }
}
