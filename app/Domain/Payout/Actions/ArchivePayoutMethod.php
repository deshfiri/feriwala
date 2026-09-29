<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Models\PayoutMethod;
use Illuminate\Database\DatabaseManager;

/**
 * Archive a payout method. Never a delete — a withdrawal already made
 * against it keeps its own frozen snapshot, so archiving changes nothing
 * about a past request; it only stops the method being offered for a new
 * one. Clears `is_default`: an archived method is never the one a future
 * withdrawal would fall back to.
 */
class ArchivePayoutMethod
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(PayoutMethod $method): PayoutMethod
    {
        return $this->database->transaction(function () use ($method) {
            /** @var PayoutMethod $locked */
            $locked = PayoutMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            $locked->transitionTo(PayoutMethodStatus::Archived);
            $locked->is_default = false;
            $locked->save();

            $this->audit->handle(new AuditEntry(
                action: 'payout_method.archived',
                auditableType: PayoutMethod::class,
                auditableId: $locked->id,
                accountId: $locked->owner_id,
                isSensitive: true,
            ));

            return $locked->refresh();
        });
    }
}
