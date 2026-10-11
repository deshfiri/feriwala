<?php

namespace App\Domain\ExternalStore\Jobs;

use App\Domain\ExternalStore\Actions\ImportExternalOrders;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Integrations\Store\Exceptions\StorePlatformException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sync one external store's orders, off the request (D29).
 *
 * Retried a couple of times with a growing pause when the store could not be
 * reached; a refusal that will not fix itself (a revoked key, a wrong address)
 * is not retried — the connection already says so, and the scheduler will try
 * again on its own round.
 */
class SyncExternalStore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $storeId,
        public readonly ExternalStoreStatusChangeSource $source = ExternalStoreStatusChangeSource::System,
    ) {
        $this->onQueue((string) config('stores.queue', 'default'));
    }

    public function handle(ImportExternalOrders $import): void
    {
        $store = ExternalStore::query()->find($this->storeId);

        if ($store === null) {
            return;
        }

        try {
            $import->handle($store, $this->source);
        } catch (StorePlatformException $failure) {
            if (! $failure->retryable) {
                $this->fail($failure);

                return;
            }

            throw $failure;
        }
    }
}
