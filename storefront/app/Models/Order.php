<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What this storefront knows of an order, so a customer can find it again. What
 * an order *is* is always read back from the ERP.
 *
 * @property string $public_id
 * @property string $reference
 * @property string $storefront_order_reference
 * @property string $access_token
 * @property string $customer_name
 * @property string $customer_mobile
 * @property string|null $customer_email
 * @property string $status
 * @property string $payment_method
 * @property string $grand_total
 * @property string $currency
 * @property string $placed_at
 */
class Order extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'public_id', 'reference', 'storefront_order_reference', 'access_token', 'customer_name', 'customer_mobile',
        'customer_email', 'status', 'payment_method', 'grand_total', 'currency', 'placed_at',
    ];
}
