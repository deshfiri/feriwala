<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff set whether a trading business funds a wholesale order's product cost
 * upfront (Conditional) or only after delivery (Non-Conditional).
 *
 * A plain admin-editable field, not a state machine: it moves back and forth
 * freely, unlike {@see AccountStatus}. Only **future** wholesale orders are
 * affected — every `orders`/`order_items` row already placed carries its own
 * `account_type` snapshot, frozen at placement, so changing this never
 * rewrites how a past order was charged.
 */
class ChangeAccountType
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor, BusinessAccount $account, AccountType $to, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this change.');
        }

        $this->database->transaction(function () use ($actor, $account, $to, $reason) {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->account_type === $to) {
                return;
            }

            $before = $locked->account_type;

            $locked->forceFill(['account_type' => $to])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.account_type_changed',
                actorId: $actor->id,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                before: ['account_type' => $before->value],
                after: ['account_type' => $to->value],
                reason: $reason,
                module: 'account',
            ));
        });

        $account->refresh();
    }
}
