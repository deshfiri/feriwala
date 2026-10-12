<?php

namespace App\Domain\Website\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Website\Actions\AllocatePackageWebsite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The website one confirmed package purchase entitles an account to (§16.1, §16.2).
 *
 * Written by {@see AllocatePackageWebsite} when a
 * subscription that includes a dedicated website becomes active. `starts_at`
 * is when the purchase was confirmed; `domain_starts_at` and
 * `hosting_starts_at` are when each package-based period begins — the same
 * moment for a first purchase, and for a renewal the later of the renewal and
 * the end of the period it continues. Nothing that happens to the website
 * afterwards — setting it up, editing it, activating it, registering its
 * domain — moves any of them.
 *
 * These are the **package's** dates. What a registrar or a hosting provider
 * actually holds lives on {@see WebsiteDomain} and {@see WebsiteHosting}, and
 * the two are never written into each other.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property int $user_package_id
 * @property int $slot which of the purchase's permitted websites this is, from 1 (D30 §9)
 * @property int|null $website_id
 * @property int|null $previous_allocation_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $domain_starts_at
 * @property CarbonImmutable $hosting_starts_at
 * @property bool $domain_included
 * @property bool $hosting_included
 * @property int|null $domain_validity_months
 * @property int|null $hosting_validity_months
 * @property CarbonImmutable|null $domain_expires_at
 * @property CarbonImmutable|null $hosting_expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BusinessAccount $businessAccount
 * @property-read UserPackage $userPackage
 * @property-read Website|null $website
 * @property-read WebsiteAllocation|null $previous
 */
class WebsiteAllocation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'starts_at' => 'immutable_datetime',
            'domain_starts_at' => 'immutable_datetime',
            'hosting_starts_at' => 'immutable_datetime',
            'domain_included' => 'boolean',
            'hosting_included' => 'boolean',
            'domain_validity_months' => 'integer',
            'hosting_validity_months' => 'integer',
            'domain_expires_at' => 'immutable_datetime',
            'hosting_expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<UserPackage, $this>
     */
    public function userPackage(): BelongsTo
    {
        return $this->belongsTo(UserPackage::class);
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<WebsiteAllocation, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_allocation_id');
    }

    /**
     * Whole days left on the package-based domain period, negative once it has
     * ended, or null when it does not end.
     */
    public function domainDaysRemaining(?CarbonImmutable $now = null): ?int
    {
        return $this->daysUntil($this->domain_expires_at, $now);
    }

    /**
     * Whole days left on the package-based hosting period, negative once it has
     * ended, or null when it does not end.
     */
    public function hostingDaysRemaining(?CarbonImmutable $now = null): ?int
    {
        return $this->daysUntil($this->hosting_expires_at, $now);
    }

    /**
     * Whether either package-based period has run out.
     */
    public function hasLapsed(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return ($this->domain_expires_at !== null && $this->domain_expires_at->lessThanOrEqualTo($now))
            || ($this->hosting_expires_at !== null && $this->hosting_expires_at->lessThanOrEqualTo($now));
    }

    protected function daysUntil(?CarbonImmutable $date, ?CarbonImmutable $now): ?int
    {
        if ($date === null) {
            return null;
        }

        return (int) ($now ?? CarbonImmutable::now())->startOfDay()
            ->diffInDays($date->startOfDay(), absolute: false);
    }
}
