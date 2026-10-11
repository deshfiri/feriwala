<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * A partner ends a store connection (D29).
 *
 * The orders stay — they happened, and the partner may still need them. The
 * credentials go: a connection nobody uses should not keep a working key to
 * somebody's shop, and the webhook secret goes with them, so a notification
 * signed with it is refused from now on. The store's address is freed for a
 * new connection.
 */
class DisconnectExternalStore
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(ExternalStore $store, User $actor, ?string $reason = null): void
    {
        if ($store->status === ExternalStoreStatus::Disconnected) {
            return;
        }

        $this->database->transaction(function () use ($store, $actor, $reason) {
            /** @var ExternalStore $locked */
            $locked = ExternalStore::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ExternalStoreStatus::Disconnected) {
                return;
            }

            $locked->forceFill([
                'api_key' => null,
                'api_secret' => null,
                'webhook_secret' => null,
                'disconnected_at' => now(),
            ]);

            $locked->transitionWithHistory(
                ExternalStoreStatus::Disconnected,
                new StatusChange(actorId: $actor->id, reason: $reason),
                ['source' => ExternalStoreStatusChangeSource::Account->value],
            );

            $store->setRawAttributes($locked->getAttributes(), sync: true);
        });

        $this->audit->handle(new AuditEntry(
            action: 'external_store.disconnected',
            actorId: $actor->id,
            auditableType: ExternalStore::class,
            auditableId: $store->id,
            reason: $reason,
            accountId: $store->business_account_id,
            module: 'external_store',
        ));
    }
}
