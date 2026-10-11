<?php

namespace App\Domain\ExternalStore\Models;

use App\Domain\ExternalStore\Enums\ExternalWebhookState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed notification an external store sent (D29).
 *
 * Unique per store and event id: the same event delivered twice is counted as
 * a duplicate and never applied twice.
 *
 * @property int $id
 * @property int $external_store_id
 * @property string $event_id
 * @property string $event
 * @property ExternalWebhookState $state
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property int $duplicate_count
 * @property string|null $error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $last_duplicate_at
 * @property-read ExternalStore $store
 */
class ExternalStoreWebhookEvent extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ExternalWebhookState::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'duplicate_count' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'last_duplicate_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(ExternalStore::class, 'external_store_id');
    }
}
