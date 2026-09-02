<?php

namespace App\Domain\Account\Models;

use App\Domain\Account\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded account status change (§5.3, §7.3).
 *
 * Append-only, like the audit log — a history that can be edited cannot settle
 * an argument about what happened.
 *
 * @property AccountStatus|null $from_status
 * @property AccountStatus $to_status
 */
class UserStatusChange extends Model
{
    protected $table = 'user_status_history';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => AccountStatus::class,
            'to_status' => AccountStatus::class,
            'notified' => 'boolean',
            'notified_at' => 'datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException(
            'Account status history is append-only. Record a new change instead.'
        ));

        static::deleting(fn () => throw new \RuntimeException(
            'Account status history is append-only and cannot be deleted.'
        ));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Whether the system made this change rather than a person.
     */
    public function wasAutomatic(): bool
    {
        return $this->changed_by === null;
    }
}
