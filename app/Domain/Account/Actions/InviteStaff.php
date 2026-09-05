<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\StaffAllowance;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\StaffInvitation;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invites someone to work in a business account as staff (D1, §8.1).
 *
 * The limit is checked **inside** a transaction that locks the account, because
 * checking it outside is the same as not checking it: two managers inviting at
 * once would both read "one seat left" and both succeed. The row lock serialises
 * them, and the partial unique index catches the duplicate-address case that a
 * lock cannot.
 *
 * Live invitations count against the limit, so ten invitations cannot be sent
 * against two seats and then all be accepted.
 */
class InviteStaff
{
    /** How long an invitation stays usable. */
    public const EXPIRES_AFTER_DAYS = 14;

    public function __construct(
        protected StaffAllowance $allowance,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws StaffLimitReached
     * @throws InvalidArgumentException
     */
    public function handle(
        BusinessAccount $account,
        User $invitedBy,
        string $email,
        AccountRole $role = AccountRole::Staff,
        ?string $mobile = null,
    ): AccountInvitation {
        $email = mb_strtolower(trim($email));

        if ($role === AccountRole::Owner) {
            throw new InvalidArgumentException(
                'An account has one owner, established at registration. There is no invitation that makes a second.'
            );
        }

        if (mb_strtolower($invitedBy->email) === $email) {
            throw new InvalidArgumentException('You are already in this account.');
        }

        $invitation = $this->database->transaction(function () use ($account, $invitedBy, $email, $role, $mobile) {
            // Locked before counting. Two managers inviting at the same moment
            // would otherwise both see the last seat as free.
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            $this->refuseIfAlreadyInside($locked, $email);

            if (! $this->allowance->hasRoom($locked)) {
                throw StaffLimitReached::at(
                    (int) $this->allowance->limit($locked),
                    $this->allowance->used($locked),
                    $this->allowance->openInvitations($locked),
                );
            }

            try {
                $invitation = AccountInvitation::create([
                    'business_account_id' => $locked->id,
                    'email' => $email,
                    'mobile' => $mobile,
                    'role' => $role,
                    'token' => Str::random(64),
                    'invited_by' => $invitedBy->id,
                    'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The partial index. Someone already has an open invitation to
                // this account at this address.
                throw new InvalidArgumentException(
                    'That address already has an open invitation to this account.'
                );
            }

            $this->audit->handle(new AuditEntry(
                action: 'account.staff_invited',
                actorId: $invitedBy->id,
                auditableType: AccountInvitation::class,
                auditableId: $invitation->id,
                after: ['email' => $email, 'role' => $role->value],
                accountId: $locked->id,
                module: 'account',
            ));

            return $invitation;
        });

        // Outside the transaction: an invitation email for a row that then
        // rolled back sends someone to a link that was never valid.
        Notification::route('mail', $email)->notify(new StaffInvitation($invitation));

        return $invitation;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function refuseIfAlreadyInside(BusinessAccount $account, string $email): void
    {
        $exists = $account->members()
            ->whereRaw('lower(users.email) = ?', [$email])
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('That person is already in this account.');
        }
    }
}
