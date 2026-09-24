<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
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
 * partner pays until somebody at Feriwala has actually registered it. Only
 * `Active` means a customer can reach the shop at this address.
 *
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

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
