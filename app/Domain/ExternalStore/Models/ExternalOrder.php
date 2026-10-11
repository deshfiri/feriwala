<?php

namespace App\Domain\ExternalStore\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\ExternalStore\Enums\StorePlatform;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An order a partner's external store took, as the store reported it (D29).
 *
 * A mirror, not a Feriwala order: no payment, stock, fulfilment, earning or
 * ledger entry hangs off it, and its status is the store's own word. Only a
 * sync from the store changes it, and the database refuses to delete it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property int $external_store_id
 * @property int|null $external_customer_id
 * @property StorePlatform $platform
 * @property string $external_id
 * @property string $order_number
 * @property string $source_status
 * @property string|null $payment_status
 * @property string|null $payment_method
 * @property array<string, mixed> $customer
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property string $currency_code
 * @property Money $subtotal
 * @property Money $discount
 * @property string|null $coupon_code
 * @property Money $shipping_total
 * @property Money $tax
 * @property Money $total
 * @property Money|null $paid_total
 * @property string|null $note
 * @property string|null $cancel_reason
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $source_updated_at
 * @property CarbonImmutable $last_synced_at
 * @property-read ExternalStore $store
 * @property-read ExternalCustomer|null $externalCustomer
 * @property-read Collection<int, ExternalOrderItem> $items
 * @property-read Collection<int, ExternalOrderEvent> $events
 */
class ExternalOrder extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('External orders are kept, never deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => StorePlatform::class,
            'customer' => 'array',
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'shipping_total' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'paid_total' => MoneyCast::class,
            'placed_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(ExternalStore::class, 'external_store_id');
    }

    /**
     * @return BelongsTo<ExternalCustomer, $this>
     */
    public function externalCustomer(): BelongsTo
    {
        return $this->belongsTo(ExternalCustomer::class);
    }

    /**
     * @return HasMany<ExternalOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ExternalOrderItem::class)->orderBy('line_number');
    }

    /**
     * @return HasMany<ExternalOrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ExternalOrderEvent::class)->orderBy('id');
    }
}
