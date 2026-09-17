<?php

namespace App\Domain\Website\Jobs;

use App\Domain\Website\Actions\AttemptWebhookDelivery;
use App\Domain\Website\Actions\DispatchDueWebhookRetries;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * One attempt to deliver one webhook (contract §7.3, P5-22).
 *
 * **One attempt per job, and the retries are ours.** A failed attempt does not
 * release this job back onto the queue for twelve hours; it writes when the
 * next attempt is due, and the scheduler dispatches it then
 * ({@see DispatchDueWebhookRetries}). A worker
 * restarting in the middle of a long backoff therefore loses nothing, and the
 * schedule is visible in the delivery record rather than hidden in a queue.
 *
 * Asynchronous by design: a slow or unreachable storefront never holds up the
 * ERP request that caused the event (§7.3).
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The schedule lives in the delivery record, not in queue retries. */
    public int $tries = 1;

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->onQueue((string) config('website.webhooks.queue', 'webhooks'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('webhook-delivery:'.$this->deliveryId))->dontRelease()];
    }

    public function handle(AttemptWebhookDelivery $attempt): void
    {
        $attempt->handle($this->deliveryId);
    }
}
