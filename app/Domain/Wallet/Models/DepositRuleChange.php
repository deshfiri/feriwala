<?php

namespace App\Domain\Wallet\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a deposit requirement, kept for good (§24.1).
 *
 * The figures either side, the person, the reason, and the date it takes effect
 * — which is not the date it was made, because §24.1 lets a deposit be decided
 * today and begin next month.
 *
 * Written once and never touched again. A record of a change that can itself be
 * changed answers nothing.
 *
 * @property int $id
 * @property int $deposit_rule_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed> $after
 * @property int|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property CarbonImmutable $created_at
 */
class DepositRuleChange extends Model
{
    public const CREATED = 'created';

    public const CLOSED = 'closed';

    public const DEACTIVATED = 'deactivated';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'A deposit rule change cannot be edited. Record the next change instead (§24.1).'
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'A deposit rule change cannot be deleted. It is why the rule says what it says (§24.1).'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DepositRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DepositRule::class, 'deposit_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
