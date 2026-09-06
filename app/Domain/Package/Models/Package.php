<?php

namespace App\Domain\Package\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use App\Domain\Package\Enums\PackageFeature;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One package an account can hold (§8).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $short_description
 * @property string|null $description
 * @property Money $fee_minor
 * @property Money|null $registration_fee_minor
 * @property Money|null $renewal_fee_minor
 * @property Money $required_deposit_minor
 * @property Money $minimum_balance_minor
 * @property string $currency_code
 * @property int|null $validity_days
 * @property string|null $renewal_frequency
 * @property int|null $grace_period_days
 * @property CarbonImmutable|null $available_from
 * @property CarbonImmutable|null $available_until
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property CarbonImmutable|null $deleted_at
 * @property-read int|null $subscriptions_count
 */
class Package extends Model
{
    use HasPublicId, HasSlug, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fee_minor' => MoneyCast::class,
            'registration_fee_minor' => MoneyCast::class,
            'renewal_fee_minor' => MoneyCast::class,
            'required_deposit_minor' => MoneyCast::class,
            'minimum_balance_minor' => MoneyCast::class,
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'validity_days' => 'integer',
            'grace_period_days' => 'integer',
            'available_from' => 'immutable_datetime',
            'available_until' => 'immutable_datetime',
        ];
    }

    /**
     * Routing uses the slug; `public_id` remains for API payloads.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<PackageFeatureValue, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PackageFeatureValue::class);
    }

    /**
     * @return HasMany<PackageCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(PackageCharge::class);
    }

    /**
     * Accounts that have bought this package (§8.2).
     *
     * What the archive guard counts, and why a retired package's row survives:
     * a subscription names the package it was for, and deleting the row would
     * take the meaning of every payment and invoice with it (§36.2).
     *
     * @return HasMany<UserPackage, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserPackage::class);
    }

    /**
     * The value of one entitlement, falling back to the feature's own default.
     *
     * Falling back rather than returning null means a caller never has to guess
     * what a missing row meant, and a package that forgets a feature grants
     * nothing rather than everything.
     */
    public function feature(PackageFeature $feature): bool|int|string|null
    {
        $row = $this->features->firstWhere('feature', $feature->value);

        if ($row === null) {
            return $feature->default();
        }

        return $feature->type()->cast($row->value);
    }

    /**
     * Whether this package is on sale right now (§8.1).
     */
    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->available_from !== null && $now->lt($this->available_from)) {
            return false;
        }

        return $this->available_until === null || $now->lt($this->available_until);
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('available_from')
                ->orWhere('available_from', '<=', now()))
            ->where(fn (Builder $q) => $q
                ->whereNull('available_until')
                ->orWhere('available_until', '>', now()))
            ->orderBy('sort_order');
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->available()->where('is_public', true);
    }
}
