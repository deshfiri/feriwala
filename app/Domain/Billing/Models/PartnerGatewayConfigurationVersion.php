<?php

namespace App\Domain\Billing\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved set of a Partner gateway's credentials (D36).
 *
 * Append-only in the database. A payment made with a Partner's gateway keeps a
 * reference to the version it started with, and settlement and reconciliation
 * resolve that exact version; the Storefront's current credentials are never
 * used for an older attempt.
 *
 * The credentials are write-only. They are encrypted at rest, hidden from every
 * serialisation, and never shown to the Partner or to an administrator again:
 * {@see presentKeys()} says which are present, never what they are.
 *
 * @property int $id
 * @property string $public_id
 * @property int $partner_gateway_configuration_id
 * @property int $business_account_id
 * @property int $website_id
 * @property int $version
 * @property string $provider
 * @property string $mode `sandbox` or `live`
 * @property array<string, string> $credentials
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read PartnerGatewayConfiguration $configuration
 */
class PartnerGatewayConfigurationVersion extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'credentials' => 'encrypted:array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * The names of the credentials held, never their values.
     *
     * @return list<string>
     */
    public function presentKeys(): array
    {
        return array_keys(array_filter($this->credentials, fn (string $value) => $value !== ''));
    }

    /**
     * @return BelongsTo<PartnerGatewayConfiguration, $this>
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(PartnerGatewayConfiguration::class, 'partner_gateway_configuration_id');
    }
}
