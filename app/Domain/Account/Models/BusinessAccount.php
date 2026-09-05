<?php

namespace App\Domain\Account\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\BusinessAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A commercial Feriwala workspace (§5, D1).
 *
 * The **business**, as distinct from the people who log into it. Everything
 * commercial hangs here — the activation funnel, KYC, packages, payments,
 * wallet, orders, websites — and the account's {@see AccountStatus} governs
 * whether the business may trade.
 *
 * It governs nothing about administration. A Feriwala staff member reviewing a
 * KYC queue needs no business account at all, and an invited staff member works
 * inside their owner's account without ever having their own. Both were
 * impossible while the commercial funnel lived on `users`: staff had to complete
 * KYC and pay an activation fee to open the admin panel.
 *
 * **One owner, one account.** D1 rules out conversion between account kinds and
 * rules out a user holding several; the database enforces both — `owner_id` is
 * unique, and a user appears at most once across all memberships.
 *
 * The UI calls this simply "Account". The class does not, because `Account`
 * alone reads as "the thing I log into" — which is precisely the confusion this
 * model exists to end.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property int $owner_id
 * @property AccountStatus $status
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $approval_pending_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $owner
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, AccountMembership> $memberships
 * @property-read Collection<int, BusinessAccountStatusChange> $statusHistory
 */
#[Fillable(['name', 'slug', 'owner_id', 'status'])]
class BusinessAccount extends Model
{
    /** @use HasFactory<BusinessAccountFactory> */
    use HasFactory, HasStateMachine, SoftDeletes;

    /*
     * Both traits offer a route key. The public id wins: it is opaque, stable,
     * and cannot be guessed from a business name, whereas a slug changes when
     * the account is renamed and would break links behind it. The slug is kept
     * for display and for the account's own storefront-facing paths (§34.2).
     */
    use HasPublicId, HasSlug {
        HasPublicId::getRouteKeyName insteadof HasSlug;
        HasSlug::getRouteKeyName as slugRouteKeyName;
    }

    /**
     * Laravel guesses `Database\Factories\Domain\Account\Models\...` from the
     * model's namespace. Domain models live deeper than the convention expects,
     * so the binding is stated rather than guessed.
     */
    protected static function newFactory(): BusinessAccountFactory
    {
        return BusinessAccountFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'activated_at' => 'immutable_datetime',
            'approval_pending_at' => 'immutable_datetime',
        ];
    }

    /**
     * Mirrors the column default so a model that has not been read back still
     * has a status — the access gates ask for it on every request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AccountStatus::Registered->value,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Everyone who may work inside this account — the owner included.
     *
     * @return BelongsToMany<User, $this, AccountMembership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_account_members', 'business_account_id', 'user_id')
            ->using(AccountMembership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<AccountMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(AccountMembership::class);
    }

    /**
     * @return HasMany<BusinessAccountStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        // `id` breaks the tie: a single decision can write two changes in the
        // same second, and ordering on the timestamp alone leaves their order
        // undefined.
        return $this->hasMany(BusinessAccountStatusChange::class)
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * The account's live subscription.
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
        return $this->hasMany(UserPackage::class);
    }

    /**
     * @return HasMany<KycSubmission, $this>
     */
    public function kycSubmissions(): HasMany
    {
        return $this->hasMany(KycSubmission::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Staff other than the owner. What a package's staff limit counts (§8.1).
     *
     * @return BelongsToMany<User, $this, AccountMembership, 'pivot'>
     */
    public function staff(): BelongsToMany
    {
        return $this->members()->wherePivot('role', '!=', AccountRole::Owner->value);
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
     * Whether this account is one person working alone.
     *
     * D1 requires the staff concept to be entirely invisible to such an account
     * — no switcher, no members list, no "team" anywhere.
     */
    public function isSolo(): bool
    {
        return $this->memberships()->count() <= 1;
    }
}
