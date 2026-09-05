<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\StaffAllowance;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Joins an invited person to a business account (D1, §8.1).
 *
 * Four refusals, each for a different way this goes wrong:
 *
 *   - the invitation is spent, revoked or expired — it is single-use, and a
 *     link that keeps working is a link that gets forwarded
 *   - the person is not who it was addressed to — an invitation grants access
 *     to someone else's business, so the address has to mean something
 *   - they already work somewhere — D1 puts a person in one account, and
 *     joining a second would resurrect the switcher P1-65 removes
 *   - the seat has gone since the invitation was sent — a package downgraded
 *     in the meantime must not be overrun by an acceptance
 *
 * The limit is re-checked here and not only at invitation time. Between the two
 * moments the package can change, other invitations can be accepted, and staff
 * can be added directly; an invitation is permission to ask, not a reservation
 * that outranks the package.
 */
class AcceptStaffInvitation
{
    public function __construct(
        protected StaffAllowance $allowance,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws StaffLimitReached
     */
    public function handle(AccountInvitation $invitation, User $user): AccountMembership
    {
        return $this->database->transaction(function () use ($invitation, $user) {
            /** @var AccountInvitation|null $locked */
            $locked = AccountInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || ! $locked->isLive()) {
                throw new InvalidArgumentException('This invitation is no longer valid.');
            }

            if (! $locked->matches($user)) {
                throw new InvalidArgumentException(
                    'This invitation was sent to someone else.'
                );
            }

            if ($user->accountMembership()->exists()) {
                throw new InvalidArgumentException(
                    'This account already works in another business. Leave that one first.'
                );
            }

            /** @var BusinessAccount $account */
            $account = BusinessAccount::query()->lockForUpdate()->findOrFail($locked->business_account_id);

            if (! $this->allowance->hasRoom($account, additional: 0)) {
                throw StaffLimitReached::at(
                    (int) $this->allowance->limit($account),
                    $this->allowance->activeStaff($account),
                    $this->allowance->openInvitations($account),
                );
            }

            try {
                $membership = $account->memberships()->create([
                    'user_id' => $user->id,
                    'role' => $locked->role,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The unique index on business_account_members.user_id. Someone
                // joined between the check above and here.
                throw new InvalidArgumentException(
                    'This account already works in another business.'
                );
            }

            $locked->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.staff_joined',
                actorId: $user->id,
                auditableType: AccountMembership::class,
                auditableId: $membership->id,
                after: ['role' => $locked->role->value],
                accountId: $account->id,
                module: 'account',
            ));

            return $membership;
        });
    }
}
