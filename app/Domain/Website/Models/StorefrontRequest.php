<?php

namespace App\Domain\Website\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A storefront write, and the answer it was given (contract §4.7, P5-21).
 *
 * Kept in the database rather than only in the cache: a storefront retrying
 * after a timeout must get the same answer even if the cache was flushed in
 * between, and "the same answer" is what stops a retry becoming a second order.
 *
 * @property int $id
 * @property int $website_id
 * @property string $idempotency_key
 * @property string $method
 * @property string $path
 * @property string $fingerprint
 * @property int $response_status
 * @property string $response_body
 * @property CarbonImmutable $created_at
 * @property-read Website $website
 */
class StorefrontRequest extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
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
