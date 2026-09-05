<?php

namespace App\Domain\Account\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Enums\AccountRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\AccountInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join a business account as staff (D1, §8.1).
 *
 * Single-use, expiring and revocable. "Live" — {@see scopeLive()} — is the state
 * that matters almost everywhere: it is what the staff limit counts, what the
 * partial unique index guards, and what an acceptance requires. Accepted,
 * revoked and expired invitations all stop being live and stop occupying a seat.
 *
 * @property string $public_id
 * @property string $email
 * @property string|null $mobile
 * @property AccountRole $role
 * @property string $token
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read BusinessAccount $businessAccount
 * @property-read User|null $invitedBy
 */
class AccountInvitation extends Model
{
    /** @use HasFactory<AccountInvitationFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = [];

    protected static function newFactory(): AccountInvitationFactory
    {
        return AccountInvitationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Still open: not accepted, not revoked, not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * Whether this person is the one who was invited (§6).
     *
     * Matched on the email the invitation was addressed to, and on the mobile
     * as well when one was given. An invitation is a grant of access to someone
     * else's business; letting whoever holds the link accept it would make the
     * address decorative.
     */
    public function matches(User $user): bool
    {
        if (mb_strtolower($user->email) !== mb_strtolower($this->email)) {
            return false;
        }

        if ($this->mobile === null) {
            return true;
        }

        return $user->mobile === $this->mobile && $user->mobile_verified_at !== null;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
