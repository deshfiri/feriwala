<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Models\UserStatusChange;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Database\DatabaseManager;

/**
 * Moves an account to a new status and records why.
 *
 * The only path a status change takes. Assigning `$user->status` directly would
 * skip the transition guard and leave no history — and §5.3 statuses gate
 * activation, trading, and access, so an unrecorded change is a hole in the
 * account's story.
 *
 * The transition and its history row are written in one database transaction:
 * a status that moved without a history entry, or a history entry for a move
 * that did not happen, would both be worse than failing.
 */
class ChangeAccountStatus
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws IllegalStateTransition
     */
    public function handle(User $user, AccountStatusChange $change): UserStatusChange
    {
        return $this->database->transaction(function () use ($user, $change) {
            $from = $user->status;

            // Throws when the move is not declared legal, before anything is
            // written.
            $user->transitionTo($change->to);

            if ($change->to->isActivated() && $user->activated_at === null) {
                $user->activated_at = now();
            }

            $user->save();

            return UserStatusChange::create([
                'user_id' => $user->id,
                'from_status' => $from,
                'to_status' => $change->to,
                'changed_by' => $change->changedBy,
                'reason' => $change->reason,
                'internal_note' => $change->internalNote,
                'user_visible_note' => $change->userVisibleNote,
            ]);
        });
    }
}
