<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Replace a store's webhook secret (D29).
 *
 * The secret is shown once, when it is made, and never again — so a partner who
 * lost it, or fears it leaked, gets a new one rather than the old one back. The
 * old secret stops working immediately; the partner pastes the new one into the
 * store.
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
        if (! $store->status->isLive()) {
            throw ExternalStoreRefused::notLive();
        }

        $secret = Str::random(48);
        $store->forceFill(['webhook_secret' => $secret])->save();

        $this->audit->handle(new AuditEntry(
            action: 'external_store.webhook_secret_rotated',
            actorId: $actor->id,
            auditableType: ExternalStore::class,
            auditableId: $store->id,
            accountId: $store->business_account_id,
            module: 'external_store',
        ));

        return $secret;
    }
}
