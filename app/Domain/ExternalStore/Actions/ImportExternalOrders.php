<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Exceptions\ExternalOrderNotRecorded;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreAllowance;
use App\Integrations\Store\Contracts\StorePlatformDriver;
use App\Integrations\Store\Data\StoreOrder;
use App\Integrations\Store\Exceptions\StorePlatformException;
use App\Integrations\Store\StorePlatformManager;
use App\Support\Concurrency\DistributedLock;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;

/**
 * Bring a store's orders up to date (D29).
 *
 * Two passes, each with **its own** cursor — the reason the warehouse OMS this
 * mirrors could lose a status change is that its passes shared one watermark:
 *
 *   1. **New orders**, walking the store's ids upward from `order_cursor`.
 *   2. **Changed orders**, everything the store says changed since
 *      `orders_updated_through` (less a small overlap), walked from the start.
 *
 * Each pass stops at a page limit, and stops if the store hands back a cursor
 * that does not move forward — a store that keeps answering "start again" must
 * not hold a worker forever. A pass that ends early resumes next run, because a
 * cursor only advances past what was actually recorded.
 *
 * One sync per store at a time (a lock, not a queue property). A failure is
 * recorded on the connection in words safe to show; after a few in a row it
 * reads as failing, and the first success after that clears it.
 *
 * Not entitled any more? Then it does nothing: the connection and its orders
 * stay, and syncing resumes if the package includes stores again.
 *
 * A successful run also closes the deferred notifications whose orders it has
 * now brought in — this pull is the reconciliation they were waiting for.
 */
class ImportExternalOrders
{
    public function __construct(
        protected StorePlatformManager $platforms,
        protected RecordExternalOrder $recorder,
        protected ExternalStoreAllowance $allowance,
        protected DistributedLock $locks,
        protected ApplyExternalStoreWebhook $webhooks,
    ) {}

    /**
     * @return array{created: int, updated: int, unreadable: int}|null null when it did not run
     *
     * @throws StorePlatformException
     */
    public function handle(ExternalStore $store, ExternalStoreStatusChangeSource $source = ExternalStoreStatusChangeSource::System): ?array
    {
        return $this->locks->attempt(
            'external-store-sync:'.$store->id,
            fn () => $this->run($store->refresh(), $source),
            ttlSeconds: 600,
        );
    }

    /**
     * @return array{created: int, updated: int, unreadable: int}|null
     *
     * @throws StorePlatformException
     */
    protected function run(ExternalStore $store, ExternalStoreStatusChangeSource $source): ?array
    {
        if (! $store->status->isLive() || ! $this->allowance->allows($store->businessAccount)) {
            return null;
        }

        $driver = $this->platforms->driver($store->platform);
        $tally = ['created' => 0, 'updated' => 0, 'unreadable' => 0];

        try {
            $this->newOrders($store, $driver, $tally);
            $this->changedOrders($store, $driver, $tally);
        } catch (StorePlatformException $failure) {
            $this->failed($store, $failure, $source);

            throw $failure;
        }

        $this->succeeded($store, $tally, $source);

        // Notifications that were waiting for this pull, and whose orders it
        // brought in, are closed rather than left saying "waiting".
        $this->webhooks->settleDeferred($store);

        return $tally;
    }

    /**
     * @param  array{created: int, updated: int, unreadable: int}  $tally
     */
    protected function newOrders(ExternalStore $store, StorePlatformDriver $driver, array &$tally): void
    {
        $cursor = $store->order_cursor;

        for ($page = 0; $page < (int) config('stores.max_pages_per_pass', 20); $page++) {
            $result = $driver->fetchOrders($store->endpoint(), $cursor);

            $this->record($store, $result->orders, $tally);
            $tally['unreadable'] += count($result->unreadable);

            // Advance only past what this page covered.
            $store->forceFill(['order_cursor' => max($cursor, $result->highestId)])->save();

            if ($result->nextCursor === null || $result->nextCursor <= $cursor) {
                return;
            }

            $cursor = $result->nextCursor;
        }
    }

    /**
     * @param  array{created: int, updated: int, unreadable: int}  $tally
     */
    protected function changedOrders(ExternalStore $store, StorePlatformDriver $driver, array &$tally): void
    {
        $startedAt = CarbonImmutable::now();
        $since = ($store->orders_updated_through ?? $startedAt->subHours((int) config('stores.initial_change_window_hours', 24)))
            ->subMinutes((int) config('stores.change_overlap_minutes', 10));

        $cursor = 0;

        for ($page = 0; $page < (int) config('stores.max_pages_per_pass', 20); $page++) {
            $result = $driver->fetchOrders($store->endpoint(), $cursor, $since);

            $this->record($store, $result->orders, $tally);
            $tally['unreadable'] += count($result->unreadable);

            if ($result->nextCursor === null || $result->nextCursor <= $cursor) {
                // The whole window was read: next time starts from here.
                $store->forceFill(['orders_updated_through' => $startedAt])->save();

                return;
            }

            $cursor = $result->nextCursor;
        }

        // Page limit reached: the window is kept, and the next run reads it again.
    }

    /**
     * @param  list<StoreOrder>  $orders
     * @param  array{created: int, updated: int, unreadable: int}  $tally
     */
    protected function record(ExternalStore $store, array $orders, array &$tally): void
    {
        foreach ($orders as $order) {
            try {
                $outcome = $this->recorder->handle($store, $order);
            } catch (ExternalOrderNotRecorded) {
                // Short of what an order needs, or an id/number mix-up at the
                // store: reported with the unreadable ones, never guessed at.
                $tally['unreadable']++;

                continue;
            }

            if ($outcome !== 'unchanged') {
                $tally[$outcome]++;
            }
        }
    }

    /**
     * @param  array{created: int, updated: int, unreadable: int}  $tally
     */
    protected function succeeded(ExternalStore $store, array $tally, ExternalStoreStatusChangeSource $source): void
    {
        $now = CarbonImmutable::now();

        $store->forceFill([
            'last_synced_at' => $now,
            'last_success_at' => $now,
            'consecutive_failures' => 0,
            'last_error' => $tally['unreadable'] > 0
                ? mb_substr(__('stores.errors.some_unreadable', ['count' => $tally['unreadable']]), 0, 255)
                : null,
        ]);

        if (in_array($store->status, [ExternalStoreStatus::Pending, ExternalStoreStatus::Failing], true)) {
            $store->transitionWithHistory(
                ExternalStoreStatus::Connected,
                StatusChange::bySystem(),
                ['source' => $source->value],
            );

            return;
        }

        $store->save();
    }

    protected function failed(ExternalStore $store, StorePlatformException $failure, ExternalStoreStatusChangeSource $source): void
    {
        $now = CarbonImmutable::now();
        $failures = $store->consecutive_failures + 1;

        $store->forceFill([
            'last_synced_at' => $now,
            'last_failure_at' => $now,
            'consecutive_failures' => $failures,
            'last_error' => mb_substr($failure->getMessage(), 0, 255),
        ]);

        $failing = $failures >= (int) config('stores.failing_after', 3)
            || ! $failure->retryable;

        if ($failing && $store->canTransitionTo(ExternalStoreStatus::Failing)) {
            $store->transitionWithHistory(
                ExternalStoreStatus::Failing,
                StatusChange::bySystem(reason: mb_substr($failure->getMessage(), 0, 255)),
                ['source' => $source->value],
            );

            return;
        }

        $store->save();
    }
}
