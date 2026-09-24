<?php

namespace App\Domain\Wholesale\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's ERP wholesale cart (§14, P4-3).
 *
 * Reached only through the signed-in user and the business account they are
 * working in; it holds intent — which units, how many — and no price, total or
 * charge. Every figure is worked out on the server whenever the cart is shown.
 *
 * A checkout confirmation (P4-8) keeps the payment method chosen and a
 * fingerprint of the checkout the person agreed to. It stands only while the
 * checkout priced now has the same fingerprint.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $business_account_id
 * @property string|null $coupon_code
 * @property string $currency_code
 * @property string|null $payment_method
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $confirmed_fingerprint
 * @property Money|null $confirmed_total
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read BusinessAccount $account
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
            'confirmed_total' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
