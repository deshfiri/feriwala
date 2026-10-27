<?php

namespace App\Domain\Damage\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a damage case's status (§18.3, D35). Append-only.
 *
 * The same shared shape every other history in the system uses. The internal
 * note is for the people running the platform; only the public note reaches the
 * Partner.
 *
 * @property int $id
 * @property int $order_damage_id
 * @property DamageStatus|null $previous_status
 * @property DamageStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property OrderStatusChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read OrderDamage $damage
 * @property-read User|null $changedBy
 */
class OrderDamageStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'order_damage_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => DamageStatus::class,
            'new_status' => DamageStatus::class,
            'source' => OrderStatusChangeSource::class,
            'changed_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
