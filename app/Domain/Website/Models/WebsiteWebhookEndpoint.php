<?php

namespace App\Domain\Website\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one address a storefront is told things at (contract §7.2, P5-20).
 *
 * One per website in v1, with its own signing secret, encrypted at rest and
 * hidden from serialisation. A rotated secret stays valid for verification for
 * a window, so a partner can roll it without dropping deliveries.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property string $url
 * @property string $secret
 * @property string $secret_hint
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property bool $is_active
 * @property CarbonImmutable|null $rotated_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 */
class WebsiteWebhookEndpoint extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['secret', 'previous_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'rotated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
