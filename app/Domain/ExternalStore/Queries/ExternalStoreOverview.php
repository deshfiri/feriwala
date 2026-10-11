<?php

namespace App\Domain\ExternalStore\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Models\ExternalStoreStatusChange;
use App\Domain\ExternalStore\Models\ExternalStoreWebhookEvent;
use App\Integrations\Store\StorePlatformManager;

/**
 * What the partner sees of their store connections (D29).
 *
 * Built only from the account's own stores. No credential is ever part of it:
 * the key is shown by its last four characters, the secrets not at all.
 */
class ExternalStoreOverview
{
    public function __construct(
        protected StorePlatformManager $platforms,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forAccount(BusinessAccount $account): array
    {
        return ExternalStore::query()
            ->forAccount($account)
            ->withCount('orders')
            ->orderByRaw("status = 'disconnected'")
            ->orderBy('name')
            ->get()
            ->map(fn (ExternalStore $store) => $this->row($store))
            ->values()
            ->all();
    }

    public function findForAccount(BusinessAccount $account, string $publicId): ?ExternalStore
    {
        /** @var ExternalStore|null $store */
        $store = ExternalStore::query()->forAccount($account)->where('public_id', $publicId)->first();

        return $store;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(ExternalStore $store): array
    {
        return [
            'id' => $store->public_id,
            'name' => $store->name,
            'base_url' => $store->base_url,
            'platform' => $store->platform->value,
            'platform_label' => $store->platform->label(),
            'status' => $store->status->value,
            'status_label' => $store->status->label(),
            'status_tone' => $store->status->tone(),
            'last_success_at' => $store->last_success_at?->toIso8601String(),
            'last_synced_at' => $store->last_synced_at?->toIso8601String(),
            'last_error' => $store->last_error,
            'orders_count' => (int) ($store->orders_count ?? $store->orders()->count()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(ExternalStore $store): array
    {
        $store->loadCount('orders');

        return [
            ...$this->row($store),
            'api_key_hint' => $store->api_key_hint,
            'has_credentials' => $store->api_key !== null,
            'connected_at' => $store->connected_at?->toIso8601String(),
            'last_failure_at' => $store->last_failure_at?->toIso8601String(),
            'last_webhook_at' => $store->last_webhook_at?->toIso8601String(),
            'disconnected_at' => $store->disconnected_at?->toIso8601String(),
            'consecutive_failures' => $store->consecutive_failures,
            'webhook' => [
                'url' => route('webhooks.stores', $store->public_id),
                'events' => $this->platforms->driver($store->platform)->webhookEvents(),
                'signature_header' => 'X-Webhook-Signature',
                'event_id_header' => 'X-Webhook-Event-Id',
            ],
            'history' => $store->statusHistory()
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ExternalStoreStatusChange $change) => [
                    'status' => $change->new_status->value,
                    'status_label' => $change->new_status->label(),
                    'source' => $change->source->value,
                    'at' => $change->changed_at->toIso8601String(),
                    'reason' => $change->reason,
                ])
                ->all(),
            'webhook_events' => ExternalStoreWebhookEvent::query()
                ->where('external_store_id', $store->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ExternalStoreWebhookEvent $event) => [
                    'event' => $event->event,
                    'state' => $event->state->value,
                    'received_at' => $event->received_at->toIso8601String(),
                    'duplicates' => $event->duplicate_count,
                    'error' => $event->error,
                ])
                ->all(),
        ];
    }
}
