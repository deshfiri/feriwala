<?php

namespace App\Domain\Website\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Synchronisation that ran out of retries (§17.3, contract §7.3, P5-26).
 *
 * The dead-letter queue: what could not be delivered after every automatic
 * attempt waits here for a person to inspect and retry. Resolving it keeps the
 * row — the record that a storefront was out of date, and for how long, is
 * worth more than a tidy table.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property string $kind
 * @property int|null $webhook_delivery_id
 * @property string $error
 * @property int $attempts
 * @property CarbonImmutable $failed_at
 * @property int $retry_count
 * @property CarbonImmutable|null $last_retried_at
 * @property int|null $last_retried_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read WebhookDelivery|null $delivery
 * @property-read User|null $lastRetriedBy
 */
class SyncQueueFailure extends Model
{
    use HasPublicId;

    public const KIND_WEBHOOK = 'webhook_delivery';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_at' => 'immutable_datetime',
            'last_retried_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
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
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastRetriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_retried_by');
    }

    /**
     * Failures nobody has put right yet.
     *
     * @param  Builder<SyncQueueFailure>  $query
     * @return Builder<SyncQueueFailure>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
