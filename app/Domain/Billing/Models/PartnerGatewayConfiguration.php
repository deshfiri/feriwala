<?php

namespace App\Domain\Billing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Website\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The gateway a Partner has chosen for one of their own Storefronts (D36).
 *
 * Only the choice lives here. The credentials sit in
 * {@see PartnerGatewayConfigurationVersion}, one immutable row per save, and a
 * payment points at a version rather than at this row.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property int $website_id
 * @property string $provider
 * @property string $status `active` or `disabled`
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BusinessAccount $businessAccount
 * @property-read Website $website
 * @property-read PartnerGatewayConfigurationVersion|null $latestVersion
 */
class PartnerGatewayConfiguration extends Model
{
    use HasPublicId;

    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return HasMany<PartnerGatewayConfigurationVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PartnerGatewayConfigurationVersion::class)->orderByDesc('version');
    }

    /**
     * What a **new** payment attempt starts with. A payment already made never
     * reads this; it holds its own version.
     *
     * @return HasOne<PartnerGatewayConfigurationVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(PartnerGatewayConfigurationVersion::class)->ofMany('version', 'max');
    }
}
