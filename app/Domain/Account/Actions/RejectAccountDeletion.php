<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\DeletionAuthority;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Notification\Actions\NotifyRequesterOfDecision;
use App\Models\User;
use App\Notifications\Requests\RequestDecided;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff say no to a request to delete an account.
 *
 * Needs the same authority as deleting, and a reason: a refusal with no
 * explanation is the one a person asks about again. The reason is for the
 * audit trail and the person who asked sees only that it was refused; what they
 * are told is the note, which is written for them.
 *
 * (Saying yes is not an action of its own — it is the permanent deletion, with
 * the impact shown first.)
 */
class RejectAccountDeletion
{
    public function __construct(
        protected DeletionAuthority $authority,
        protected RecordAuditLog $audit,
        protected NotifyRequesterOfDecision $notify,
        protected DatabaseManager $database,
    ) {}

    public function handle(AccountDeletionRequest $request, User $actor, string $note): AccountDeletionRequest
    {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Say why the request is being refused.');
        }

        $subject = $request->subject();

        if ($subject !== null && ($refusal = $this->authority->refusal($actor, $subject)) !== null) {
            throw new AuthorizationException($refusal);
        }

        $decided = $this->database->transaction(function () use ($request, $actor, $note) {
            /** @var AccountDeletionRequest $locked */
            $locked = AccountDeletionRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw new InvalidArgumentException('That request has already been decided.');
            }

            $locked->forceFill([
                'status' => DeletionRequestStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.deletion_rejected',
                actorId: $actor->id,
                auditableType: AccountDeletionRequest::class,
                auditableId: $locked->id,
                note: $note,
                module: 'account',
            ));

            return $locked;
        });

        $this->notify->handle(
            $decided->subject(),
            RequestDecided::DELETION,
            false,
            $decided->public_id,
        );

        return $decided;
    }
}
