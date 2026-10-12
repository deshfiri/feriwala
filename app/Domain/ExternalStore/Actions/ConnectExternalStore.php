<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Enums\StorePlatform;
use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreAllowance;
use App\Integrations\Store\Data\StoreEndpoint;
use App\Integrations\Store\Exceptions\StorePlatformException;
use App\Integrations\Store\StorePlatformManager;
use App\Integrations\Store\Support\StoreAddress;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * A partner connects one of their own stores (D29).
 *
 * In order, and each step refuses on its own terms:
 *
 *   1. The package must include external stores, with room for another.
 *   2. The address must be HTTPS and not already held by a live connection —
 *      this account's or anybody else's. Whose is not said.
 *   3. The store must answer the credentials: one order is read, which proves
 *      the address, the key and the `orders.read` scope in one call.
 *   4. The connection is saved under the partner's own account, credentials
 *      encrypted, with the webhook secret the store already signs with — if
 *      the partner gave one — or one Feriwala generates. The capacity check
 *      is repeated under a lock on the account, and the database's one-live-
 *      owner index settles a race for the same store.
 *   5. The first import is queued — never run inside this request.
 *
 * Returns a generated webhook secret in plain text **once**, for the setup
 * screen. It is not readable again; it can only be replaced. A secret the
 * partner provided is never handed back — they already have it — so the
 * result carries null instead.
 */
class ConnectExternalStore
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
        BusinessAccount $account,
        User $actor,
        StorePlatform $platform,
        string $name,
        string $baseUrl,
        #[SensitiveParameter] string $apiKey,
        #[SensitiveParameter] string $apiSecret,
        #[SensitiveParameter] ?string $providedWebhookSecret = null,
    ): array {
        $address = $this->checkPreconditions($account, $platform, $baseUrl);

        $this->verify($platform, new StoreEndpoint($address->baseUrl, $apiKey, $apiSecret));

        $webhookSecret = $providedWebhookSecret ?? Str::random(48);

        try {
            $store = $this->database->transaction(function () use (
                $account, $actor, $platform, $name, $address, $apiKey, $apiSecret, $webhookSecret
            ) {
                BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->first();

                if (! $this->allowance->hasRoomFor($account)) {
                    throw ExternalStoreRefused::limitReached($this->allowance->limit($account));
                }

                $store = ExternalStore::create([
                    'business_account_id' => $account->id,
                    'platform' => $platform,
                    'name' => $name,
                    'base_url' => $address->baseUrl,
                    'normalized_host' => $address->normalizedHost,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                    'api_key_hint' => mb_substr($apiKey, -4),
                    'webhook_secret' => $webhookSecret,
                    'status' => ExternalStoreStatus::Pending,
                    'connected_at' => now(),
                    'created_by' => $actor->id,
                ]);

                $store->recordStatusChange(
                    null,
                    ExternalStoreStatus::Pending,
                    new StatusChange(actorId: $actor->id),
                    ['source' => ExternalStoreStatusChangeSource::Account->value],
                );

                return $store;
            });
        } catch (UniqueConstraintViolationException) {
            throw ExternalStoreRefused::alreadyConnected();
        }

        $this->audit->handle(new AuditEntry(
            action: 'external_store.connected',
            actorId: $actor->id,
            auditableType: ExternalStore::class,
            auditableId: $store->id,
            after: [
                'name' => $store->name,
                'platform' => $platform->value,
                'host' => $store->normalized_host,
                'webhook_signing_origin' => $providedWebhookSecret === null ? 'feriwala' : 'store',
            ],
            accountId: $account->id,
            module: 'external_store',
        ));

        SyncExternalStore::dispatch($store->id)->afterCommit();

        return ['store' => $store, 'webhook_secret' => $providedWebhookSecret === null ? $webhookSecret : null];
    }

    /**
     * Steps 1–3 without saving anything: what "Check connection" runs before
     * the partner confirms.
     *
     * @throws ExternalStoreRefused
     */
    public function check(
        BusinessAccount $account,
        StorePlatform $platform,
        string $baseUrl,
        #[SensitiveParameter] string $apiKey,
        #[SensitiveParameter] string $apiSecret,
    ): void {
        $address = $this->checkPreconditions($account, $platform, $baseUrl);

        $this->verify($platform, new StoreEndpoint($address->baseUrl, $apiKey, $apiSecret));
    }

    /**
     * @throws ExternalStoreRefused
     */
    protected function checkPreconditions(BusinessAccount $account, StorePlatform $platform, string $baseUrl): StoreAddress
    {
        if (! $this->allowance->allows($account)) {
            throw ExternalStoreRefused::notEntitled();
        }

        if (! $this->allowance->hasRoomFor($account)) {
            throw ExternalStoreRefused::limitReached($this->allowance->limit($account));
        }

        $address = StoreAddress::parse($baseUrl) ?? throw ExternalStoreRefused::addressInvalid();

        $taken = ExternalStore::query()
            ->live()
            ->where('platform', $platform->value)
            ->where('normalized_host', $address->normalizedHost)
            ->exists();

        if ($taken) {
            throw ExternalStoreRefused::alreadyConnected();
        }

        return $address;
    }

    /**
     * @throws ExternalStoreRefused
     */
    protected function verify(StorePlatform $platform, StoreEndpoint $endpoint): void
    {
        try {
            $this->platforms->driver($platform)->verify($endpoint);
        } catch (StorePlatformException $failure) {
            throw ExternalStoreRefused::verificationFailed($failure->getMessage());
        }
    }
}
