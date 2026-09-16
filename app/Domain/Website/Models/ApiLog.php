<?php

namespace App\Domain\Website\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One call a storefront made to the ERP, as it was answered (contract §9, §42, P5-27).
 *
 * Written once and never changed — the database refuses an update, and so does
 * this model. The request identifier is the one the error body carried, so a
 * partner quoting it in a support conversation leads straight here.
 *
 * Nothing in `request_summary` is a secret or unmasked personal data; it was
 * redacted before it was stored.
 *
 * @property int $id
 * @property string $request_id
 * @property int|null $website_id
 * @property int|null $website_credential_id
 * @property string|null $key_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property int $duration_ms
 * @property string|null $error_code
 * @property string|null $ip
 * @property array<string, mixed>|null $request_summary
 * @property CarbonImmutable $created_at
 * @property-read Website|null $website
 * @property-read WebsiteCredential|null $credential
 */
class ApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_summary' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An API log is evidence and is never edited.'));
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<WebsiteCredential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(WebsiteCredential::class, 'website_credential_id');
    }
}
