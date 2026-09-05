<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Concerns\HasTeams;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A human login identity (§6, D23).
 *
 * A person, not a business. Whether they may sign in at all is
 * {@see UserStatus}; whether their business may trade is
 * {@see AccountStatus} on their
 * {@see BusinessAccount}. Keeping the two apart is what lets a Feriwala staff
 * member administer the platform without commercial KYC and an activation fee,
 * and lets an invited staff member work in someone else's account without
 * onboarding of their own.
 *
 * @property int $id
 * @property string $public_id
 * @property UserStatus $identity_status
 * @property CarbonImmutable|null $identity_status_changed_at
 * @property string $name
 * @property string $email
 * @property string|null $mobile
 * @property Carbon|null $email_verified_at
 * @property CarbonImmutable|null $mobile_verified_at
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
 * @property-read Collection<int, UserAddress> $addresses
 * @property-read BusinessAccount|null $businessAccount
 * @property-read BusinessAccount|null $ownedAccount
 * @property-read AccountMembership|null $accountMembership
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
     * the database still has an identity status.
     *
     * Without it a fresh `create()` leaves the column unset until the row is
     * re-read, and the identity gate — which asks on every request — fatals on
     * null for a user that is perfectly valid in the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'identity_status' => UserStatus::Active->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'identity_status' => UserStatus::class,
            'identity_status_changed_at' => 'immutable_datetime',
            'email_verified_at' => 'datetime',
            // The application runs on immutable dates (AppServiceProvider), so
            // new columns are cast to match rather than quietly handing back a
            // mutable Carbon that behaves differently under modification.
            'mobile_verified_at' => 'immutable_datetime',
            'date_of_birth' => 'immutable_date',
            'terms_accepted_at' => 'immutable_datetime',
            'privacy_accepted_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<UserAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * The business account this person works in, if any (D1, D23).
     *
     * Null for Feriwala platform staff, who administer the system without
     * trading on it. Everything commercial — KYC, packages, payments, wallet,
     * orders — hangs off this rather than off the person.
     *
     * Singular because D1 puts a person in exactly one business, and the unique
     * index on `business_account_members.user_id` holds them to it.
     *
     * @return HasOneThrough<BusinessAccount, AccountMembership, $this>
     */
    public function businessAccount(): HasOneThrough
    {
        return $this->hasOneThrough(
            BusinessAccount::class,
            AccountMembership::class,
            'user_id',
            'id',
            'id',
            'business_account_id',
        );
    }

    /**
     * @return HasOne<AccountMembership, $this>
     */
    public function accountMembership(): HasOne
    {
        return $this->hasOne(AccountMembership::class);
    }

    /**
     * The account this person owns, if they own one.
     *
     * Distinct from {@see BusinessAccount()}: an invited staff member works in
     * an account they do not own, and only an owner is ever asked to complete
     * KYC, choose a package or pay an activation fee (D23).
     *
     * @return HasOne<BusinessAccount, $this>
     */
    public function ownedAccount(): HasOne
    {
        return $this->hasOne(BusinessAccount::class, 'owner_id');
    }

    public function ownsBusinessAccount(): bool
    {
        return $this->ownedAccount()->exists();
    }

    /**
     * Whether this person may work inside the given account (D1).
     */
    public function belongsToAccount(BusinessAccount $account): bool
    {
        return $this->accountMembership()
            ->where('business_account_id', $account->id)
            ->exists();
    }

    public function isAccountOwner(): bool
    {
        return $this->accountMembership()->value('role') === TeamRole::Owner->value;
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

    /**
     * Whether this person may reach the platform at all (§6, D23).
     *
     * The identity question, and the only one that outranks everything else: a
     * suspended login loses the admin panel as surely as it loses the wallet,
     * whatever roles it holds and whatever state its business is in.
     *
     * Deliberately **not** called `isActivated()`. That name asked a commercial
     * question of a person, and answering it from `users` is what made staff
     * complete KYC and pay an activation fee to open the admin panel.
     */
    public function hasPlatformAccess(): bool
    {
        return $this->identity_status->permitsAccess();
    }

    /**
     * Whether this person's business may trade (§5.3).
     *
     * False for platform staff, who have no business account — and rightly so:
     * it governs the commercial ERP, which they do not use.
     */
    public function canTransact(): bool
    {
        return $this->businessAccount?->canTransact() ?? false;
    }

    /**
     * Whether this person's business has been activated (§5.1).
     *
     * The question the business ERP gate asks. Platform staff answer false and
     * are unaffected: their gate is identity plus permission.
     */
    public function hasActivatedBusinessAccount(): bool
    {
        return $this->businessAccount?->isActivated() ?? false;
    }

    /**
     * Whether both required verifications are complete (§5.1).
     */
    public function isVerified(): bool
    {
        return $this->email_verified_at !== null && $this->mobile_verified_at !== null;
    }

    /**
     * The state machine guards the identity, not the business.
     *
     * `status` moved to {@see BusinessAccount} with D23, so the trait's default
     * column no longer exists here. Pointing it at `identity_status` keeps
     * lock, suspend and close going through a checked transition rather than a
     * bare assignment.
     */
    public function stateAttribute(): string
    {
        return 'identity_status';
    }
}
