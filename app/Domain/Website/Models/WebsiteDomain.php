<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasStateMachine;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\WebsiteDomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain registered for a website, and when it runs out (§16.2, §41, P5-11).
 *
 * Registered by hand (D9), so a row sits in `Pending` from the moment the
 * partner pays until somebody at Feriwala has actually registered it.
 *
 * Whether the shared storefront may answer on it is a second, separate state
 * (D30 §3): `routing_status`, moved only through transitionTo(). A domain
 * serves when its registration is running **and** it is verified and active.
 *
 * @property DomainRoutingStatus $routing_status
 * @property string|null $verification_token published in DNS; shown to staff only
 * @property CarbonImmutable|null $verification_issued_at
 * @property CarbonImmutable|null $verification_checked_at
 * @property string|null $verification_error
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $tls_authorized_at
 * @property int $id
 * @property int $website_id
 * @property string $domain
 * @property string|null $registrar
 * @property WebsiteServiceStatus $status
 * @property string $currency_code
 * @property Money $fee
 * @property CarbonImmutable|null $registered_at
 * @property CarbonImmutable|null $expires_at
 * @property bool $auto_renew
 * @property CarbonImmutable|null $reminded_at
 * @property int|null $reminder_stage
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read User|null $createdBy
 */
class WebsiteDomain extends Model
{
    /** @use HasFactory<WebsiteDomainFactory> */
    use HasFactory;

    use HasStateMachine;

    protected $guarded = [];

    /**
     * The database's default, so a domain just created reads as unverified
     * before it is ever reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'routing_status' => 'unverified',
    ];

    /**
     * The state transitionTo() guards. The registration status is kept by
     * staff as a record of D9 provisioning and has no transition map.
     */
    public function stateAttribute(): string
    {
        return 'routing_status';
    }

    /**
     * The name in the DNS record that proves control of this domain.
     */
    public function verificationRecordName(): string
    {
        return '_banij-verify.'.$this->domain;
    }

    /**
     * Whether the storefront may answer on this domain right now.
     */
    public function serves(): bool
    {
        return $this->routing_status === DomainRoutingStatus::Active && $this->status->isRunning();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routing_status' => DomainRoutingStatus::class,
            'verification_issued_at' => 'immutable_datetime',
            'verification_checked_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'tls_authorized_at' => 'immutable_datetime',
            'status' => WebsiteServiceStatus::class,
            'fee' => MoneyCast::class,
            'registered_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'auto_renew' => 'boolean',
            'reminded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Domain models live deeper than the factory naming convention expects, so
     * the binding is stated rather than guessed.
     */
    protected static function newFactory(): WebsiteDomainFactory
    {
        return WebsiteDomainFactory::new();
    }

    /**
     * How many days are left on the registration, or null when it has no term.
     */
    public function daysRemaining(?CarbonImmutable $now = null): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) ($now ?? CarbonImmutable::now())->startOfDay()
            ->diffInDays($this->expires_at->startOfDay(), absolute: false);
    }

    /**
     * Registrations running out on or before a date.
     *
     * @param  Builder<WebsiteDomain>  $query
     * @return Builder<WebsiteDomain>
     */
    public function scopeExpiringBy(Builder $query, CarbonImmutable $date): Builder
    {
        return $query->where('status', WebsiteServiceStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $date);
    }
}
