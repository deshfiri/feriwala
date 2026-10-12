<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\ExternalStore\Enums\ExternalOrderEventSource;
use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use App\Domain\ExternalStore\Enums\ExternalWebhookState;
use App\Domain\ExternalStore\Exceptions\ExternalOrderNotRecorded;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderEvent;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Models\ExternalStoreWebhookEvent;
use App\Integrations\Store\Exceptions\UnreadableStoreOrder;
use App\Integrations\Store\StorePlatformManager;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Apply one received store notification (D29).
 *
 *   - An **order** event records the order through the same idempotent path a
 *     sync uses, so a webhook and a sync can never write it twice.
 *   - A **payment** confirmation is recorded as an event on the order and
 *     updates what the store says was paid. It is the store's account of its
 *     own payment — no Feriwala payment, wallet or ledger is involved.
 *   - Anything else is understood and ignored.
 *
 * Once applied, the stored payload is cleared: it held a customer's details and
 * is not needed again. A failed one keeps it, so a retry has something to apply.
 *
 * A **deferred** one — too thin to create its order — waits for the pull API.
 * Once its order has arrived it is closed as processed without being applied
 * ({@see settleDeferred()}, and on a redelivery), because the pulled copy is
 * newer than anything the old event could say.
 */
class ApplyExternalStoreWebhook
{
    public function __construct(
        protected StorePlatformManager $platforms,
        protected RecordExternalOrder $recorder,
        protected DatabaseManager $database,
    ) {}

    public function handle(ExternalStoreWebhookEvent $record): void
    {
        if (in_array($record->state, [ExternalWebhookState::Processed, ExternalWebhookState::Ignored], true)) {
            return;
        }

        $store = $record->store;

        if (! $store->status->isLive()) {
            $this->finish($record, ExternalWebhookState::Ignored);

            return;
        }

        $record->forceFill(['attempts' => $record->attempts + 1])->save();

        try {
            $webhook = $this->platforms->driver($store->platform)->readWebhook($record->payload);

            if ($webhook->order !== null) {
                // A deferred notification is never applied late. Its order
                // came in through the pull API after it — the store's current
                // copy — so the old event has nothing newer to say.
                if ($record->state === ExternalWebhookState::Deferred
                    && $this->orderExists($record->external_store_id, $webhook->order->orderNumber)) {
                    $this->finish($record, ExternalWebhookState::Processed);

                    return;
                }

                try {
                    $this->recorder->handle($store, $webhook->order, $record->event_id);
                } catch (ExternalOrderNotRecorded $notRecorded) {
                    $this->notRecorded($record, $notRecorded);

                    return;
                }

                $this->finish($record, ExternalWebhookState::Processed);

                return;
            }

            if ($webhook->payment !== null) {
                $this->payment($record, $webhook->payment);

                return;
            }

            $this->finish($record, ExternalWebhookState::Ignored);
        } catch (UnreadableStoreOrder $unreadable) {
            $this->fail($record, __('stores.errors.webhook_unreadable', ['reason' => $unreadable->getMessage()]));
        } catch (Throwable $failure) {
            $this->fail($record, __('stores.errors.webhook_failed'));

            throw $failure;
        }
    }

    /**
     * @param  array{order_number: string, amount: Money|null, method: string|null, received_at: CarbonImmutable|null}  $payment
     */
    protected function payment(ExternalStoreWebhookEvent $record, array $payment): void
    {
        $this->database->transaction(function () use ($record, $payment) {
            /** @var ExternalOrder|null $order */
            $order = ExternalOrder::query()
                ->where('external_store_id', $record->external_store_id)
                ->where('order_number', $payment['order_number'])
                ->lockForUpdate()
                ->first();

            // Not imported yet: leave it for the sync, which will bring the
            // order with its payment status. Nothing is invented here.
            if ($order === null) {
                $this->finish($record, ExternalWebhookState::Ignored);

                return;
            }

            $already = ExternalOrderEvent::query()
                ->where('external_order_id', $order->id)
                ->where('external_event_id', $record->event_id)
                ->exists();

            if (! $already) {
                $previous = $order->payment_status;

                if ($payment['amount'] !== null) {
                    $order->paid_total = $payment['amount'];
                }

                $order->payment_status = 'paid';
                $order->save();

                ExternalOrderEvent::create([
                    'external_order_id' => $order->id,
                    'type' => ExternalOrderEventType::PaymentReceived,
                    'source' => ExternalOrderEventSource::ExternalStore,
                    'previous_value' => $previous,
                    'new_value' => 'paid',
                    'description' => $payment['method'],
                    'external_event_id' => $record->event_id,
                    'occurred_at' => $payment['received_at'],
                    'recorded_at' => now(),
                ]);
            }

            $this->finish($record, ExternalWebhookState::Processed);
        });
    }

    /**
     * Close the store's deferred notifications whose orders a sync has now
     * brought in (D29).
     *
     * Called after a successful pull. The pulled copy is the store's current
     * one and supersedes each of them, so they are marked processed — not
     * applied — and the customer details they held are cleared. One whose
     * order is still missing stays deferred and says why.
     *
     * @return int how many were closed
     */
    public function settleDeferred(ExternalStore $store): int
    {
        $driver = $this->platforms->driver($store->platform);
        $settled = 0;

        ExternalStoreWebhookEvent::query()
            ->where('external_store_id', $store->id)
            ->where('state', ExternalWebhookState::Deferred->value)
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($driver, &$settled) {
                foreach ($records as $record) {
                    /** @var ExternalStoreWebhookEvent $record */
                    try {
                        $number = $driver->readWebhook($record->payload)->order?->orderNumber;
                    } catch (UnreadableStoreOrder) {
                        continue;
                    }

                    if ($number !== null && $this->orderExists($record->external_store_id, $number)) {
                        $this->finish($record, ExternalWebhookState::Processed);
                        $settled++;
                    }
                }
            });

        return $settled;
    }

    protected function orderExists(int $storeId, string $orderNumber): bool
    {
        return ExternalOrder::query()
            ->where('external_store_id', $storeId)
            ->where('order_number', $orderNumber)
            ->exists();
    }

    protected function finish(ExternalStoreWebhookEvent $record, ExternalWebhookState $state): void
    {
        $record->forceFill([
            'state' => $state,
            'processed_at' => now(),
            'error' => null,
            'payload' => ['cleared' => true],
        ])->save();
    }

    /**
     * A notification that cannot become an order as it stands.
     *
     * Too little to create a new order (no store id, an amount or the items
     * missing): kept as deferred — payload and all — and a sync is queued, so
     * the order arrives complete from the pull API. An id and an order number
     * that belong to two different orders: failed, and nothing merged.
     */
    protected function notRecorded(ExternalStoreWebhookEvent $record, ExternalOrderNotRecorded $notRecorded): void
    {
        if (! $notRecorded->incomplete) {
            $this->fail($record, __('stores.errors.webhook_identity_conflict'));

            return;
        }

        $record->forceFill([
            'state' => ExternalWebhookState::Deferred,
            'error' => mb_substr(__('stores.errors.webhook_deferred', ['reason' => $notRecorded->getMessage()]), 0, 255),
        ])->save();

        SyncExternalStore::dispatch($record->external_store_id);
    }

    protected function fail(ExternalStoreWebhookEvent $record, string $error): void
    {
        $record->forceFill([
            'state' => ExternalWebhookState::Failed,
            'error' => mb_substr($error, 0, 255),
        ])->save();
    }
}
