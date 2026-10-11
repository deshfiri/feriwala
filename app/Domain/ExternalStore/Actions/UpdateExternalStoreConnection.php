<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreAllowance;
use App\Integrations\Store\Data\StoreEndpoint;
use App\Integrations\Store\Exceptions\StorePlatformException;
use App\Integrations\Store\StorePlatformManager;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Change a connection, or connect a disconnected store again (D29).
 *
 * The address is not editable: it is what the store *is*, and pointing a
 * connection at another shop would graft one store's history onto another.
 * A different store is a new connection.
 *
 *   - **Rename** — just the name.
 *   - **New credentials** — both key and secret, verified before they replace
 *     the old ones. A key that does not work is never saved.
 *   - **Reconnect** — a disconnected store, with fresh credentials: checked
 *     against the package and the one-live-owner rule like a new connection,
 *     given a new webhook secret, moved back to pending, and synced again from
 *     where its cursors left off. Its earlier orders are still there.
 *
 * Returns a new webhook secret, in plain text, only when it made one.
 */
class UpdateExternalStoreConnection
{
    public function __construct(
        protected StorePlatformManager $platforms,
        protected ExternalStoreAllowance $allowance,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return array{store: ExternalStore, webhook_secret: string|null}
     *
     * @throws ExternalStoreRefused
     */
    public function handle(
        ExternalStore $store,
        User $actor,
        string $name,
        #[SensitiveParameter] ?string $apiKey = null,
        #[SensitiveParameter] ?string $apiSecret = null,
    ): array {
        $reconnecting = $store->status === ExternalStoreStatus::Disconnected;
        $newCredentials = filled($apiKey) && filled($apiSecret);

        if (($reconnecting && ! $newCredentials) || (filled($apiKey) xor filled($apiSecret))) {
            throw ExternalStoreRefused::credentialsRequired();
        }

        if ($reconnecting) {
            $account = $store->businessAccount;

            if (! $this->allowance->allows($account)) {
                throw ExternalStoreRefused::notEntitled();
            }

            if (! $this->allowance->hasRoomFor($account)) {
                throw ExternalStoreRefused::limitReached($this->allowance->limit($account));
            }
        }

        if ($newCredentials) {
            try {
                $this->platforms->driver($store->platform)
                    ->verify(new StoreEndpoint($store->base_url, (string) $apiKey, (string) $apiSecret));
            } catch (StorePlatformException $failure) {
                throw ExternalStoreRefused::verificationFailed($failure->getMessage());
            }
        }

        $webhookSecret = $reconnecting ? Str::random(48) : null;

        try {
            $this->database->transaction(function () use ($store, $actor, $name, $apiKey, $apiSecret, $newCredentials, $reconnecting, $webhookSecret) {
                /** @var ExternalStore $locked */
                $locked = ExternalStore::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

                $locked->name = $name;

                if ($newCredentials) {
                    $locked->api_key = $apiKey;
                    $locked->api_secret = $apiSecret;
                    $locked->api_key_hint = mb_substr((string) $apiKey, -4);
                }

                if ($reconnecting) {
                    $locked->webhook_secret = $webhookSecret;
                    $locked->disconnected_at = null;
                    $locked->consecutive_failures = 0;
                    $locked->last_error = null;
                    $locked->connected_at = now();

                    $locked->transitionWithHistory(
                        ExternalStoreStatus::Pending,
                        new StatusChange(actorId: $actor->id, reason: 'reconnected'),
                        ['source' => ExternalStoreStatusChangeSource::Account->value],
                    );
                } else {
                    $locked->save();
                }

                $store->setRawAttributes($locked->getAttributes(), sync: true);
            });
        } catch (UniqueConstraintViolationException) {
            throw ExternalStoreRefused::alreadyConnected();
        }

        $this->audit->handle(new AuditEntry(
            action: $reconnecting ? 'external_store.reconnected' : 'external_store.updated',
            actorId: $actor->id,
            auditableType: ExternalStore::class,
            auditableId: $store->id,
            after: ['name' => $store->name, 'credentials_replaced' => $newCredentials],
            accountId: $store->business_account_id,
            module: 'external_store',
        ));

        if ($reconnecting) {
            SyncExternalStore::dispatch($store->id)->afterCommit();
        }

        return ['store' => $store, 'webhook_secret' => $webhookSecret];
    }
}
