<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * The person who asked changes their mind.
 *
 * Only the person it is about can withdraw it, and only while it is still open.
 */
class WithdrawAccountDeletion
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(AccountDeletionRequest $request, User|Supplier $by): AccountDeletionRequest
    {
        $mine = $by instanceof Supplier
            ? $request->supplier_id === $by->id
            : $request->user_id === $by->id;

        if (! $mine) {
            // The same answer as a request that does not exist: nobody learns
            // whether somebody else asked.
            throw new InvalidArgumentException('That request is not yours to withdraw.');
        }

        return $this->database->transaction(function () use ($request, $by) {
            /** @var AccountDeletionRequest $locked */
            $locked = AccountDeletionRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw new InvalidArgumentException('That request has already been decided.');
            }

            $locked->forceFill(['status' => DeletionRequestStatus::Withdrawn, 'decided_at' => now()])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.deletion_withdrawn',
                actorId: $by instanceof User ? $by->id : null,
                auditableType: AccountDeletionRequest::class,
                auditableId: $locked->id,
                module: 'account',
            ));

            return $locked;
        });
    }
}
