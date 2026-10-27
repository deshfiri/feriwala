<?php

namespace App\Domain\Damage\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What a damage costs, and who bears it (D35).
 *
 * The case's accounting record, with the same shape and rules as a return's
 * charge. A charge borne by the Partner is also a wallet transaction (or an
 * outstanding liability, when the wallet could not cover it and must not go
 * negative); a charge borne by Banij exists here and only here, because there
 * is no platform wallet to post it to.
 *
 * Immutable in what it is — its case, kind, bearer, amount and idempotency key
 * cannot be rewritten, and it is never deleted. Only where it stands moves. A
 * mistake is answered by a second row of entry type `reversal` pointing at the
 * charge it puts right.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_damage_id
 * @property int $business_account_id
 * @property ReturnChargeKind $kind
 * @property string $entry_type
 * @property int|null $reverses_charge_id
 * @property ReturnParty $bearer
 * @property Money $amount
 * @property string $currency_code
 * @property ReturnChargeStatus $status
 * @property int|null $wallet_transaction_id
 * @property string $reason
 * @property int|null $actor_id
 * @property string $idempotency_key
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable|null $settled_at
 * @property-read OrderDamage $damage
 * @property-read BusinessAccount $businessAccount
 * @property-read WalletTransaction|null $walletTransaction
 */
class DamageCharge extends Model
{
    use HasPublicId;

    public const CHARGE = 'charge';

    public const REVERSAL = 'reversal';

    protected $table = 'order_damage_charges';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException('A charge is never deleted; a mistake is answered by a reversal.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReturnChargeKind::class,
            'bearer' => ReturnParty::class,
            'status' => ReturnChargeStatus::class,
            'amount' => MoneyCast::class,
            'posted_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
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
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isReversal(): bool
    {
        return $this->entry_type === self::REVERSAL;
    }

    /**
     * Whether this charge still stands as a cost: a charge that was not reversed.
     */
    public function isLive(): bool
    {
        return ! $this->isReversal() && $this->status->stands();
    }
}
