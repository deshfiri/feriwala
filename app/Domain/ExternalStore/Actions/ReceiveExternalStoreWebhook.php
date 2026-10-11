<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\ExternalStore\Enums\ExternalWebhookState;
use App\Domain\ExternalStore\Jobs\ProcessExternalStoreWebhook;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Models\ExternalStoreWebhookEvent;
use App\Integrations\Store\StorePlatformManager;
use App\Integrations\Store\Support\ExactJson;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;

/**
 * Accept one notification from a partner's store (D29).
 *
 * Unauthenticated by necessity, so the order of checks is the security:
 *
 *   1. The store must be live; a disconnected one has no secret to check with.
 *   2. The signature must be the HMAC of the **raw** body under this store's
 *      own secret, compared in constant time.
 *   3. It must carry the store's event id — without one a replay could not be
 *      recognised, so it is refused rather than given a made-up id.
 *   4. It is written down once per store and event id. A repeat is counted and
 *      acknowledged, and applied again only if the first attempt failed.
 *
 * Applying it is queued: a store waiting on our database is a store that times
 * out and sends the same event again.
 */
class ReceiveExternalStoreWebhook
{
    public function __construct(
        protected StorePlatformManager $platforms,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, string|null>  $headers  lower-cased names
     * @return 'accepted'|'duplicate'|'unauthorised'|'malformed'|'unknown_store'
     */
    public function handle(string $storePublicId, string $rawBody, array $headers): string
    {
        /** @var ExternalStore|null $store */
        $store = ExternalStore::query()->live()->where('public_id', $storePublicId)->first();

        if ($store === null || $store->webhook_secret === null) {
            return 'unknown_store';
        }

        $driver = $this->platforms->driver($store->platform);

        if (! $driver->signatureIsValid($rawBody, $headers, $store->webhook_secret)) {
            return 'unauthorised';
        }

        $eventId = $driver->webhookEventId($headers);

        try {
            $payload = ExactJson::decode($rawBody);
        } catch (JsonException) {
            return 'malformed';
        }

        if ($eventId === null || ! is_array($payload)) {
            return 'malformed';
        }

        $event = is_string($payload['event'] ?? null) ? mb_substr($payload['event'], 0, 64) : 'unknown';
        $now = now();

        try {
            /** @var ExternalStoreWebhookEvent $record */
            $record = $this->database->transaction(fn () => ExternalStoreWebhookEvent::create([
                'external_store_id' => $store->id,
                'event_id' => $eventId,
                'event' => $event,
                'state' => ExternalWebhookState::Received,
                'payload' => $payload,
                'received_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->repeat($store, $eventId);
        }

        $store->forceFill(['last_webhook_at' => $now])->save();

        ProcessExternalStoreWebhook::dispatch($record->id);

        return 'accepted';
    }

    /**
     * @return 'duplicate'
     */
    protected function repeat(ExternalStore $store, string $eventId): string
    {
        /** @var ExternalStoreWebhookEvent $record */
        $record = ExternalStoreWebhookEvent::query()
            ->where('external_store_id', $store->id)
            ->where('event_id', $eventId)
            ->firstOrFail();

        $record->forceFill([
            'duplicate_count' => $record->duplicate_count + 1,
            'last_duplicate_at' => now(),
        ])->save();

        // Only a failed first attempt is worth another go; a processed or
        // ignored event has already had its effect, or has none.
        if ($record->state === ExternalWebhookState::Failed) {
            ProcessExternalStoreWebhook::dispatch($record->id);
        }

        return 'duplicate';
    }
}
