<?php

namespace App\Domain\Website\Models;

use App\Concerns\HasPublicId;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer of one partner website (contract §6.2, §16.3, P5-13, P5-23).
 *
 * Keyed on the website and a normalised mobile number: **the same number on two
 * partners' shops is two customers**, and nothing here reaches across. A guest
 * is never an ERP user — no account, no KYC, no wallet.
 *
 * This is the live record, for the shop's own customer list. What an order says
 * about who placed it is the order's own snapshot, and a later edit here never
 * rewrites it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property string $mobile
 * @property string $name
 * @property string|null $email
 * @property string|null $storefront_customer_reference
 * @property bool $is_guest
 * @property CarbonImmutable|null $last_order_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 */
class WebsiteCustomer extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'last_order_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
