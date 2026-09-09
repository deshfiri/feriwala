<?php

namespace App\Domain\Account\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in, and the device it came from (§6).
 *
 * Kept after the session ends, because the history is the point: a person asking
 * "was that me in March?" is asking about a session that no longer exists.
 *
 * The session identifier is stored encrypted and is never sent to a browser —
 * ending a session needs it, and nothing else does. What the screen shows is the
 * device, the address and when it was last used.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $session_key
 * @property string|null $session_id
 * @property string $device_fingerprint
 * @property string $device_label
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $last_active_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $ended_reason
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
class AuthenticatedSession extends Model
{
    use HasPublicId;

    /** Ended because the person deliberately signed out of that device. */
    public const ENDED_SIGNED_OUT = 'signed_out';

    /** Ended from the security screen — "log out other devices". */
    public const ENDED_REVOKED = 'revoked';

    /** Ended because the password changed, so every other session had to go. */
    public const ENDED_PASSWORD_CHANGED = 'password_changed';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted at rest: a database dump must not be a set of live
            // session identifiers.
            'session_id' => 'encrypted',
            'last_active_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sessions that have not been ended.
     *
     * "Live" here means "not ended by us". A session whose Redis record has
     * expired on its own still shows until something notices — which is why the
     * screen orders by last activity and says when that was, rather than
     * claiming a session is currently in use.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * The hash a session identifier is stored under.
     *
     * One place, because a lookup that hashes differently from the write finds
     * nothing and silently creates a second row for the same session.
     */
    public static function keyFor(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
