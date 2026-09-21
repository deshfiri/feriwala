<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Notifications\SupplierResetPassword;
use App\Domain\Supplier\Notifications\SupplierVerifyEmail;
use App\Models\User;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Database\Factories\SupplierFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A Supplier: a wholly separate account domain from Client/Partner (D25,
 * P13-1).
 *
 * Authenticated through its own guard (`config/auth.php` `supplier`), never
 * through `web`. This is **not** a role, a capability, or a status reachable
 * from `App\Models\User` or `BusinessAccount` — a person who is both a
 * business owner and runs a supplying business holds two entirely separate
 * logins, exactly as two unrelated people would.
 *
 * `reviewed_by`/decisions reference `App\Models\User` because that is the one
 * place a Supplier row legitimately points at the Client/Partner/staff
 * identity table: the **staff member** who decided, never the Supplier's own
 * identity.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $mobile
 * @property CarbonImmutable|null $mobile_verified_at
 * @property string $password
 * @property SupplierStatus $status
 * @property string $business_name
 * @property string $contact_person_name
 * @property string $business_address
 * @property string|null $trade_licence_number
 * @property string|null $tax_identification_number
 * @property array<string, mixed>|null $payout_details
 * @property string $locale
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $closed_at
 * @property-read User|null $reviewedBy
 * @property-read Collection<int, SupplierStatusChange> $statusHistory
 */
#[Fillable([
    'business_name', 'contact_person_name', 'business_address',
    'trade_licence_number', 'tax_identification_number', 'locale',
    'email', 'mobile', 'password',
])]
#[Hidden(['password', 'remember_token', 'payout_details'])]
class Supplier extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasPublicId, HasReference, HasStateMachine, Notifiable, RecordsStatusHistory;

    protected $guarded = [];

    protected $attributes = [
        'status' => SupplierStatus::Draft->value,
        'locale' => 'en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierStatus::class,
            'email_verified_at' => 'immutable_datetime',
            'mobile_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            // Bank/payout detail is commercially sensitive in the way KYC
            // documents are (D25) — encrypted at rest, independent of the
            // application-layer confidentiality the policies also enforce.
            'payout_details' => 'encrypted:array',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Supplier;
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<SupplierKycSubmission, $this>
     */
    public function kycSubmissions(): HasMany
    {
        return $this->hasMany(SupplierKycSubmission::class)->orderByDesc('round');
    }

    /**
     * @return HasMany<SupplierProductListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(SupplierProductListing::class);
    }

    /**
     * @return HasMany<SupplierOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }

    /**
     * @return HasMany<SupplierStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupplierStatusChange::class)->orderBy('id');
    }

    /**
     * Where a text message goes (§30-equivalent). The verified mobile only —
     * an unverified number is one nobody confirmed belongs to this Supplier.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->mobile_verified_at === null ? null : $this->mobile;
    }

    /**
     * Whether both required verifications are complete.
     */
    public function isVerified(): bool
    {
        return $this->email_verified_at !== null && $this->mobile_verified_at !== null;
    }

    /**
     * Whether this Supplier may submit listing requests or reach operational
     * Supplier screens. The one question every server-side guard asks —
     * never the navigation, which only hides a link the guard would refuse
     * anyway.
     */
    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    /**
     * Sent against `supplier.verification.verify`, never the Client/Partner
     * `verification.verify` route Fortify owns (D25).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new SupplierVerifyEmail);
    }

    /**
     * Sent against `supplier.password.reset` and the `suppliers` broker,
     * never the Client/Partner `password.reset` route or `users` broker (D25).
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new SupplierResetPassword($token));
    }
}
