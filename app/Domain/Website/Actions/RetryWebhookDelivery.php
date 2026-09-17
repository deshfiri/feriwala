<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Jobs\DeliverWebhook;
use App\Domain\Website\Models\SyncQueueFailure;
use App\Domain\Website\Models\WebhookDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * A person sending a failed delivery again (contract §7.3, §17.2, P5-25, P5-26).
 *
 * Only a delivery in the dead-letter state, and with **the same event
 * identifier** — the storefront may have received an earlier attempt without
 * acknowledging it, and the identifier is what lets it recognise the retry as
 * the same event rather than a second one.
 *
 * The retry is one more attempt, not a fresh schedule: the attempt counter
 * continues, and if this one fails the delivery goes straight back to the
 * failed queue for a person to look at again. Recorded on the failure, on the
 * delivery and in the audit trail.
 */
class RetryWebhookDelivery
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(WebhookDelivery $delivery, User $actor): WebhookDelivery
    {
        $now = CarbonImmutable::now();

        $this->database->transaction(function () use ($delivery, $actor, $now) {
            /** @var WebhookDelivery $locked */
            $locked = WebhookDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($locked->state !== WebhookDeliveryState::Failed) {
                throw WebsiteRefused::nothingToRetry();
            }

            $locked->forceFill([
                // One attempt now, counted as the last one the schedule allows:
                // if it fails, the delivery goes straight back to the failed
                // queue. How many times a person retried is on the failure.
                'state' => WebhookDeliveryState::Retrying,
                'next_retry_at' => $now,
                'attempt' => min($locked->attempt, 8),
                'retried_by' => $actor->id,
                'retried_at' => $now,
            ])->save();

            SyncQueueFailure::query()
                ->where('webhook_delivery_id', $locked->id)
                ->unresolved()
                ->increment('retry_count', 1, [
                    'last_retried_at' => $now,
                    'last_retried_by' => $actor->id,
                ]);

            $delivery->setRawAttributes($locked->getAttributes(), sync: true);
        });

        $this->audit->handle(new AuditEntry(
            action: 'website.webhook_retried',
            actorId: $actor->id,
            auditableType: WebhookDelivery::class,
            auditableId: $delivery->id,
            after: ['event_id' => $delivery->event_id, 'event_type' => $delivery->event_type->value],
            module: 'website',
        ));

        $this->database->afterCommit(fn () => DeliverWebhook::dispatch($delivery->id));

        return $delivery;
    }
}
