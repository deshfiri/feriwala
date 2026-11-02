<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A visitor's basket; the token is the cookie.
 *
 * @property int $id
 * @property string $token
 */
class Cart extends Model
{
    protected $fillable = ['token'];

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
