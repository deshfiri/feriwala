<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A checkout from details to a placed order.
 *
 * @property int $id
 * @property string $token
 * @property string|null $reference
 * @property string $idempotency_key
 * @property string $status
 * @property array<string, mixed> $customer_json
 * @property array<string, mixed> $shipping_json
 * @property list<array<string, mixed>> $items_json
 * @property string $payment_method
 * @property string|null $gateway
 * @property string|null $customer_note
 * @property array<string, mixed>|null $quote_json
 * @property string|null $placed_order_id
 */
class Checkout extends Model
{
    public const DRAFT = 'draft';

    public const PLACING = 'placing';

    public const PLACED = 'placed';

    protected $fillable = [
        'token', 'cart_id', 'reference', 'idempotency_key', 'status', 'customer_json', 'shipping_json',
        'billing_json', 'items_json', 'payment_method', 'gateway', 'customer_note', 'quote_json', 'placed_order_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_json' => 'array',
            'shipping_json' => 'array',
            'billing_json' => 'array',
            'items_json' => 'array',
            'quote_json' => 'array',
        ];
    }
}
