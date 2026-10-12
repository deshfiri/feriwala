<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Models\User;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Replace a store's webhook secret (D29).
 *
 * Two ways in, and either way the old secret stops working immediately:
 *
 *   - **Generated.** Feriwala makes a new secret, shown once, for the partner
 *     to paste into the store. For a lost secret, or one feared leaked.
 *   - **Provided.** The store generated its own secret and the partner enters
 *     it here. It is saved encrypted and never handed back — the partner
 *     already has it.
 *
 * Neither the secret nor any part of it reaches the audit log.
 */
class RotateExternalStoreWebhookSecret
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws ExternalStoreRefused
     */
    public function handle(ExternalStore $store, User $actor): string
    {
        $secret = Str::random(48);

        $this->replace($store, $actor, $secret, 'feriwala');

        return $secret;
    }

    /**
     * @throws ExternalStoreRefused
     */
    public function provide(ExternalStore $store, User $actor, #[SensitiveParameter] string $secret): void
    {
        $this->replace($store, $actor, $secret, 'store');
    }

    /**
     * @param  'feriwala'|'store'  $source
     *
     * @throws ExternalStoreRefused
     */
    protected function replace(ExternalStore $store, User $actor, #[SensitiveParameter] string $secret, string $source): void
    {
        if (! $store->status->isLive()) {
            throw ExternalStoreRefused::notLive();
        }

        $store->forceFill(['webhook_secret' => $secret])->save();

        $this->audit->handle(new AuditEntry(
            action: 'external_store.webhook_secret_rotated',
            actorId: $actor->id,
            auditableType: ExternalStore::class,
            auditableId: $store->id,
            after: ['webhook_signing_origin' => $source],
            accountId: $store->business_account_id,
            module: 'external_store',
        ));
    }
}
