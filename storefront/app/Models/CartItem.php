<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a basket: a SKU and a quantity, with the name and picture to show.
 * Never a price — that is whatever the ERP says when the cart is quoted.
 *
 * The table's key is (cart_id, sku), so rows are addressed with `where()`.
 */
class CartItem extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'cart_id', 'sku', 'product_id', 'product_slug', 'product_name', 'variant_label', 'image_url', 'quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
