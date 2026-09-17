<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Jobs\DeliverWebhook;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Tell one storefront that something changed (contract §7, §17.2, P5-22, P5-25).
 *
 * **Filtered by ownership before anything is queued** (§7.2). The caller names
 * the website the change belongs to, and the delivery goes to that website's
 * endpoint and no other — one partner never receives another's events, by
 * construction rather than by a filter at the consumer.
 *
 * Nothing is queued for a website with no active endpoint, or one that is not
 * receiving synchronisation (§16.4). Such a storefront reads the API when it
 * next runs; a webhook is only ever a hint (§7.5).
 *
 * **Coalesced.** A burst of edits to one product — a partner nudging a price
 * five times — becomes one pending delivery carrying the latest payload, not
 * five. Only a delivery that has not been attempted is updated: one already on
 * its way keeps the event identifier the storefront may have seen.
 *
 * Delivery happens **after the surrounding transaction commits**, so a change
 * that rolls back never tells a storefront it happened.
 */
class PublishWebsiteEvent
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the entity's own fields, never a cost or a margin
     */
    public function handle(
        Website $website,
        WebhookEvent $event,
        array $data,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): ?WebhookDelivery {
        // A suspended shop is exactly the one that must hear it was suspended,
        // and restored (contract §7.1); nothing else goes to a shop not served.
        $aboutTheWebsite = in_array($event, [WebhookEvent::WebsiteSuspended, WebhookEvent::WebsiteRestored], true);

        if (! $aboutTheWebsite && ! $website->status->acceptsSync()) {
            return null;
        }

        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()
            ->where('website_id', $website->id)
            ->where('is_active', true)
            ->first();

        if ($endpoint === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        $payload = [
            'event' => $event->value,
            'occurred_at' => $now->toIso8601String(),
            'website_id' => $website->public_id,
            'data' => $data,
        ];

        if ($subjectType !== null && $subjectId !== null) {
            /** @var WebhookDelivery|null $pending */
            $pending = WebhookDelivery::query()
                ->where('website_id', $website->id)
                ->where('event_type', $event->value)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('state', WebhookDeliveryState::Pending->value)
                ->where('attempt', 0)
                ->first();

            if ($pending !== null) {
                $pending->forceFill(['payload' => $payload])->save();

                return $pending;
            }
        }

        $delivery = WebhookDelivery::create([
            'event_id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'website_webhook_endpoint_id' => $endpoint->id,
            'event_type' => $event,
            'payload_version' => 1,
            'payload' => $payload,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'state' => WebhookDeliveryState::Pending,
            'attempt' => 0,
        ]);

        $this->database->afterCommit(fn () => DeliverWebhook::dispatch($delivery->id));

        return $delivery;
    }
}
