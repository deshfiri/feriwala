<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Somebody asks to have their account deleted.
 *
 * Nobody deletes their own account: it holds records other people rely on, and
 * an authorised member of staff decides. Asking puts the request in the staff
 * queue, and the person can withdraw it until it is decided.
 *
 * Asking twice is asking once — an open request is handed back rather than a
 * second one made — so a double click, or a retry, cannot flood the queue.
 */
class RequestAccountDeletion
{
    /** Long enough to be a reason, short enough not to be a hurdle. */
    public const MINIMUM_REASON = 5;

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(User|Supplier $subject, string $reason): AccountDeletionRequest
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('Tell us briefly why you want the account deleted.');
        }

        $column = $subject instanceof Supplier ? 'supplier_id' : 'user_id';

        if (($existing = $this->openRequest($column, $subject)) !== null) {
            return $existing;
        }

        try {
            $request = $this->database->transaction(function () use ($subject, $column, $reason) {
                $request = AccountDeletionRequest::create([
                    $column => $subject->getKey(),
                    'reason' => $reason,
                    'requested_at' => now(),
                ]);

                $this->audit->handle(new AuditEntry(
                    action: 'account.deletion_requested',
                    actorId: $subject instanceof User ? $subject->id : null,
                    auditableType: AccountDeletionRequest::class,
                    auditableId: $request->id,
                    reason: $reason,
                    module: 'account',
                ));

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // Two clicks at once: the other one won, and its request is the answer.
            return $this->openRequest($column, $subject) ?? throw new InvalidArgumentException('A deletion request is already open.');
        }

        ($subject instanceof Supplier ? StaffQueue::SupplierDeletion : StaffQueue::AccountDeletion)->announce($request);

        return $request;
    }

    /**
     * Reads the database, so two calls can differ: another request may land between them.
     *
     * @phpstan-impure
     */
    protected function openRequest(string $column, User|Supplier $subject): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()->where($column, $subject->getKey())->where('status', 'pending')->first();
    }
}
