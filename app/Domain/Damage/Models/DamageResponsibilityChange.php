<?php

namespace App\Domain\Damage\Models;

use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision about who bears a damage (D35). Append-only: the history of what
 * was believed, by whom, when and why is never rewritten, only added to.
 *
 * @property int $id
 * @property int $order_damage_id
 * @property ReturnParty|null $previous_party
 * @property ReturnParty $new_party
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property string $reason
 * @property-read OrderDamage $damage
 * @property-read User|null $changedByUser
 */
class DamageResponsibilityChange extends Model
{
    protected $table = 'order_damage_responsibility_changes';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_party' => ReturnParty::class,
            'new_party' => ReturnParty::class,
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
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
