<?php

namespace App\Domain\Audit\Models;

use App\Concerns\HasPublicId;
use App\Domain\Audit\Exceptions\AuditLogIsImmutable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded sensitive action (§36.2).
 *
 * Append-only. The guards below stop Eloquent from updating or deleting a row;
 * database triggers stop everything else. Both exist because either alone can be
 * bypassed — the model guard by raw SQL, the trigger by a migration that drops
 * it — and an audit trail that can be quietly rewritten is worse than none,
 * since it still reads as authoritative.
 *
 * @property string $public_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
class AuditLog extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'is_sensitive' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $log) {
            throw AuditLogIsImmutable::cannotUpdate($log->public_id);
        });

        static::deleting(function (self $log) {
            throw AuditLogIsImmutable::cannotDelete($log->public_id);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeSensitive(Builder $query): Builder
    {
        return $query->where('is_sensitive', true);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
