<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreAllowance;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queue a sync for every store connection that may sync now (D29).
 *
 * The scheduled safety net beside webhooks: a notification the store never
 * sent, or one lost on the way, is caught on the next round. Only live
 * connections of accounts whose package still includes external stores — and
 * which may trade — are queued; a store whose package lapsed keeps its orders
 * and waits. Each sync takes its own lock, so a round that overlaps a manual
 * "Sync now" does no harm.
 */
class DispatchExternalStoreSyncs
{
    public function __construct(
        protected ExternalStoreAllowance $allowance,
    ) {}

    public function handle(): int
    {
        $count = 0;

        /** @var array<int, bool> $eligible account id => may sync */
        $eligible = [];

        ExternalStore::query()
            ->live()
            ->with('businessAccount')
            ->orderBy('id')
            ->chunkById(200, function (Collection $stores) use (&$count, &$eligible) {
                foreach ($stores as $store) {
                    /** @var ExternalStore $store */
                    $eligible[$store->business_account_id] ??= $this->allowance->allows($store->businessAccount);

                    if (! $eligible[$store->business_account_id]) {
                        continue;
                    }

                    SyncExternalStore::dispatch($store->id, ExternalStoreStatusChangeSource::Scheduler);
                    $count++;
                }
            });

        return $count;
    }
}
