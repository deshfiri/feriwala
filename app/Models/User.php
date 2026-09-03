<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Concerns\HasTeams;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Account\Models\UserStatusChange;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Models\UserPackage;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A Feriwala account.
 *
 * One account structure — no separate customer, partner, wholesale or
 * dropshipping account, and no conversion between them (§5, §44). The same row
 * moves through {@see AccountStatus} and, once Active, may do dropshipping,
 * wholesale purchasing, or both.
 *
 * @property int $id
 * @property string $public_id
 * @property AccountStatus $status
 * @property string $name
 * @property string $email
 * @property string|null $mobile
 * @property Carbon|null $email_verified_at
 * @property CarbonImmutable|null $mobile_verified_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $approval_pending_at
 * @property string|null $referral_code
 * @property int|null $referred_by_user_id
 * @property string $locale
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, UserStatusChange> $statusHistory
 * @property-read Collection<int, UserAddress> $addresses
 */
#[Fillable([
    'name', 'email', 'mobile', 'password', 'current_team_id',
    'date_of_birth', 'gender', 'country', 'nationality', 'locale',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, HasStateMachine, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /*
     * Both traits define teams(). They mean different things: HasTeams::teams
     * is the account's own membership relation, while Spatie's is the set of
     * role-scoping teams. The account relation is the one the application uses,
     * so it wins; Spatie's is kept under a distinct name rather than dropped,
     * because the package calls it internally when teams mode is enabled (D2).
     *
     * This resolves when Team becomes Account in P1-64.
     */
    use HasRoles, HasTeams {
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as spatieRoleTeams;
    }

    /**
     * Mirrors the column default, so a model that has not been read back from
     * the database still has a status.
     *
     * Without it a fresh `create()` leaves `status` unset until the row is
     * re-read, and `$user->status->isActivated()` — which the §5.4 funnel gate
     * asks on every request — fatals on null for a user that is perfectly valid
     * in the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AccountStatus::Registered->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'email_verified_at' => 'datetime',
            // The application runs on immutable dates (AppServiceProvider), so
            // new columns are cast to match rather than quietly handing back a
            // mutable Carbon that behaves differently under modification.
            'mobile_verified_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'approval_pending_at' => 'immutable_datetime',
            'date_of_birth' => 'immutable_date',
            'terms_accepted_at' => 'immutable_datetime',
            'privacy_accepted_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<UserStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        // `id` breaks the tie. A single decision can write two changes in the
        // same second — picked up for review, then decided — and ordering on
        // the timestamp alone leaves their order undefined, so the history can
        // render the steps backwards or return the wrong one to `first()`.
        return $this->hasMany(UserStatusChange::class)
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * @return HasMany<UserAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * The account's current subscription.
     *
     * Read entitlements through {@see Entitlements} rather
     * than from here — a raw relation tempts callers into their own feature
     * checks, which is how limits drift apart across the codebase.
     *
     * @return BelongsTo<UserPackage, $this>
     */
    public function currentPackage(): BelongsTo
    {
        return $this->belongsTo(UserPackage::class, 'current_user_package_id');
    }

    /**
     * @return HasMany<UserPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(UserPackage::class)->latest('created_at');
    }

    /**
     * The account that referred this one. Single level only — there is no
     * downline, and none may be added (§25.1, §44).
     *
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }

    /**
     * Accounts this one has referred. Unlimited in number (§25.1).
     *
     * @return HasMany<User, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

    public function isActivated(): bool
    {
        return $this->status->isActivated();
    }

    public function canTransact(): bool
    {
        return $this->status->canTransact();
    }

    /**
     * Whether both required verifications are complete (§5.1).
     */
    public function isVerified(): bool
    {
        return $this->email_verified_at !== null && $this->mobile_verified_at !== null;
    }
}
