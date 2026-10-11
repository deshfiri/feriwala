<?php

namespace App\Domain\ExternalStore\Jobs;

use App\Domain\ExternalStore\Actions\ApplyExternalStoreWebhook;
use App\Domain\ExternalStore\Models\ExternalStoreWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Apply one received store notification, off the request (D29).
 */
class ProcessExternalStoreWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $webhookEventId,
    ) {
        $this->onQueue((string) config('stores.queue', 'default'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('external-store-webhook:'.$this->webhookEventId))->dontRelease()];
    }

    public function handle(ApplyExternalStoreWebhook $apply): void
    {
        $record = ExternalStoreWebhookEvent::query()->find($this->webhookEventId);

        if ($record !== null) {
            $apply->handle($record);
        }
    }
}
