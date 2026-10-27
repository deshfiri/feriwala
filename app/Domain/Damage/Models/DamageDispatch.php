<?php

namespace App\Domain\Damage\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A damaged item on its way to the Partner who bears the loss (D35).
 *
 * Requested by the Partner, who pays the delivery: the charge is worked out by
 * the delivery-charge service from the item's own logistics and never taken from
 * a request, and is debited from the wallet in the same transaction that creates
 * the dispatch. One dispatch per damage, enforced by a unique index, so the same
 * units cannot be sent twice. The address is the Partner's own, copied as it
 * stood when it was asked for.
 *
 * Tracking is recorded by staff when it leaves, and the Partner (or staff for
 * them) records that it arrived.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_damage_id
 * @property int $business_account_id
 * @property int $quantity
 * @property int $shared_address_id
 * @property array<string, mixed> $address_snapshot
 * @property string $currency_code
 * @property Money $delivery_charge
 * @property array<string, mixed> $delivery_calculation
 * @property DamageDispatchStatus $status
 * @property int|null $requested_by
 * @property CarbonImmutable $requested_at
 * @property int|null $courier_provider_id
 * @property string|null $tracking_number
 * @property int|null $dispatched_by
 * @property CarbonImmutable|null $dispatched_at
 * @property int|null $stock_movement_id
 * @property int|null $partner_stock_movement_id
 * @property int|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property string $idempotency_key
 * @property-read OrderDamage $damage
 * @property-read BusinessAccount $businessAccount
 * @property-read SharedAddress $address
 * @property-read CourierProvider|null $courierProvider
 */
class DamageDispatch extends Model
{
    use HasPublicId;

    protected $table = 'order_damage_dispatches';

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['idempotency_key'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException('A dispatch is never deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DamageDispatchStatus::class,
            'delivery_charge' => MoneyCast::class,
            'address_snapshot' => 'array',
            'delivery_calculation' => 'array',
            'requested_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderDamage, $this>
     */
    public function damage(): BelongsTo
    {
        return $this->belongsTo(OrderDamage::class, 'order_damage_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<SharedAddress, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(SharedAddress::class, 'shared_address_id');
    }

    /**
     * @return BelongsTo<CourierProvider, $this>
     */
    public function courierProvider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class);
    }
}
