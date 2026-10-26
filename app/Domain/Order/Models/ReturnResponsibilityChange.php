<?php

namespace App\Domain\Order\Models;

use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to who a case is attributed to (D35). Append-only.
 *
 * Attribution is history: the first row says who the case was opened against
 * and the rows after say who changed it, when, and why. The previous value is
 * kept on every row, so what was believed at any moment can be read back, and
 * nothing already written is rewritten.
 *
 * @property int $id
 * @property int $order_return_id
 * @property ReturnParty|null $previous_party
 * @property ReturnParty $new_party
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property string $reason
 * @property-read OrderReturn $orderReturn
 * @property-read User|null $changedBy
 */
class ReturnResponsibilityChange extends Model
{
    protected $table = 'order_return_responsibility_changes';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

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
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
