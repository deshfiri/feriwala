<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\BusinessAccountStatusChange;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Database\DatabaseManager;

/**
 * Moves a business account to a new status and records why (§5.3).
 *
 * The only path a commercial status change takes. Assigning `$account->status`
 * directly would skip the transition guard and leave no history — and §5.3
 * statuses gate trading, so an unrecorded change is a hole in the account's
 * story.
 *
 * Operates on the **account**, not the person (D23). Locking or suspending a
 * login is a different question with different consequences, and lives on
 * {@see UserStatus}.
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
    public function handle(BusinessAccount $account, AccountStatusChange $change): BusinessAccountStatusChange
    {
        return $this->database->transaction(function () use ($account, $change) {
            $from = $account->status;

            // Throws when the move is not declared legal, before anything is
            // written.
            $account->transitionTo($change->to);

            if ($change->to->isActivated() && $account->activated_at === null) {
                $account->activated_at = now();
            }

            $account->save();

            return BusinessAccountStatusChange::create([
                'business_account_id' => $account->id,
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
