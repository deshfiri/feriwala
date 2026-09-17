<?php

namespace App\Domain\Website\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One attempt to deliver a webhook, as it was answered (contract §7.4, §42, P5-27).
 *
 * Written once. The headers were redacted before they were stored — the
 * signature never reaches this table — and the response is an excerpt, not a
 * mirror of whatever the storefront chose to send back.
 *
 * @property int $id
 * @property int $webhook_delivery_id
 * @property int $website_id
 * @property int $attempt
 * @property string $url
 * @property int|null $response_status
 * @property int $duration_ms
 * @property array<string, mixed> $request_headers
 * @property string|null $response_excerpt
 * @property string|null $error
 * @property CarbonImmutable $created_at
 * @property-read WebhookDelivery $delivery
 */
class WebhookLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A webhook log is evidence and is never edited.'));
    }

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
