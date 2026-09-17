<?php

namespace App\Domain\Website\Models;

use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebhookEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event, and every attempt to tell one storefront about it (contract §7.4).
 *
 * The `event_id` never changes across retries: it is sent as
 * `X-Feriwala-Delivery` and is what the storefront deduplicates on, because
 * delivery is at least once (§7.3).
 *
 * @property int $id
 * @property string $event_id
 * @property int $website_id
 * @property int|null $website_webhook_endpoint_id
 * @property WebhookEvent $event_type
 * @property int $payload_version
 * @property array<string, mixed> $payload
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property WebhookDeliveryState $state
 * @property int $attempt
 * @property int|null $response_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable|null $next_retry_at
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $retried_by
 * @property CarbonImmutable|null $retried_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read WebsiteWebhookEndpoint|null $endpoint
 * @property-read Collection<int, WebhookLog> $logs
 * @property-read User|null $retriedBy
 */
class WebhookDelivery extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => WebhookEvent::class,
            'payload' => 'array',
            'state' => WebhookDeliveryState::class,
            'dispatched_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'retried_at' => 'immutable_datetime',
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
     * @return BelongsTo<WebsiteWebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebsiteWebhookEndpoint::class, 'website_webhook_endpoint_id');
    }

    /**
     * @return HasMany<WebhookLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function retriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retried_by');
    }
}
