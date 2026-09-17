<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Jobs\DeliverWebhook;
use App\Domain\Website\Models\WebhookDelivery;
use Carbon\CarbonImmutable;

/**
 * Send the retries that have come due (contract §7.3, P5-26).
 *
 * The backoff lives in the delivery record, so the schedule survives a worker
 * restart, a deploy, or a queue flushed in the middle of a twelve-hour wait.
 * Every minute this finds the deliveries whose next attempt is due and queues
 * them; the attempt itself re-checks the record under a row lock, so a retry
 * queued twice is sent once.
 */
class DispatchDueWebhookRetries
{
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;

        WebhookDelivery::query()
            ->where('state', WebhookDeliveryState::Retrying->value)
            ->where('next_retry_at', '<=', $now)
            ->orderBy('next_retry_at')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$queued) {
                DeliverWebhook::dispatch($id);
                $queued++;
            });

        return $queued;
    }
}
