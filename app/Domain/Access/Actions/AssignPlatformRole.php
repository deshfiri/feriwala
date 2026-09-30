<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Assigns a platform staff member the full set of roles they should hold
 * afterward -- one or more of the twenty-one {@see PlatformRole} enum cases,
 * and (from the custom-role management half of this batch) any
 * database-backed role an administrator has created. This replaces the
 * subject's whole role set; it does not add to it -- callers that mean
 * "add" or "remove one" compute the new full set themselves first.
 *
 * Rules refused here rather than in the policy, because `Gate::before`
 * passes a Super Admin actor straight over a policy method:
 *
 * **Only a Super Admin may grant Super Admin.** Otherwise anybody holding
 * `access.edit` could hand a third party the one role that passes every
 * check unconditionally, a strictly bigger power than `access.edit` is
 * meant to carry. Every *other* fixed role is an ordinary grant behind
 * `access.edit` — assigning `WalletManager` or `KycManager` is precisely
 * what a centralized role-assignment screen is for, and does not require
 * the assigning admin to personally hold every permission the role
 * bundles.
 *
 * **Nobody may grant a *custom* role carrying a permission they do not
 * themselves hold.** A custom role's permission bundle was invented by
 * whoever created it, not hand-declared and reviewed the way the fixed
 * twenty-one are — so here, unlike the fixed roles, the actor's own
 * authority is the ceiling. Otherwise anybody holding `access.edit` could
 * bundle a sensitive permission into a role they invent and grant it to a
 * third party (or themselves).
 *
 * **The platform's last active Super Admin may never lose the role** —
 * self-change included. If theirs were the only active login holding it,
 * the recovery from that mistake is a database console.
 */
class AssignPlatformRole
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  list<string>  $roleNames  every role this subject should hold afterward
     */
    public function handle(User $subject, User $actor, array $roleNames, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Assigning a role requires a recorded reason.');
        }

        if ($roleNames === []) {
            throw new InvalidArgumentException('A platform staff member needs at least one role.');
        }

        if ($subject->accountMembership()->exists()) {
            throw new InvalidArgumentException('Only a platform staff member may hold a platform role.');
        }

        $this->assertWithinActorAuthority($actor, $roleNames);

        $this->database->transaction(function () use ($subject, $roleNames, $actor, $reason) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);

            $before = $locked->getRoleNames()->all();

            if (
                in_array(PlatformRole::SuperAdmin->value, $before, true)
                && ! in_array(PlatformRole::SuperAdmin->value, $roleNames, true)
                && ! $this->anotherActiveSuperAdminExists($locked)
            ) {
                throw new InvalidArgumentException('The platform must always have at least one active Super Admin.');
            }

            $locked->syncRoles($roleNames);

            $this->audit->handle(new AuditEntry(
                action: 'access.role_assigned',
                actorId: $actor->id,
                auditableType: User::class,
                auditableId: $subject->id,
                before: ['roles' => $before],
                after: ['roles' => $roleNames],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));
        });
    }

    /**
     * @param  list<string>  $roleNames
     */
    protected function assertWithinActorAuthority(User $actor, array $roleNames): void
    {
        if ($actor->hasRole(PlatformRole::SuperAdmin->value)) {
            return;
        }

        foreach ($roleNames as $roleName) {
            $platformRole = PlatformRole::tryFrom($roleName);

            if ($platformRole !== null) {
                if ($platformRole === PlatformRole::SuperAdmin) {
                    throw new InvalidArgumentException('Only a Super Admin may grant the Super Admin role.');
                }

                continue;
            }

            foreach ($this->permissionsFor($roleName) as $permission) {
                if (! $actor->can($permission)) {
                    throw new InvalidArgumentException(
                        "Granting the [{$roleName}] role would hand over the [{$permission}] permission, which you do not hold yourself."
                    );
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function permissionsFor(string $roleName): array
    {
        $platformRole = PlatformRole::tryFrom($roleName);

        if ($platformRole !== null) {
            return array_values($platformRole->permissions());
        }

        /** @var list<string> */
        return Role::findByName($roleName)->permissions->pluck('name')->values()->all();
    }

    /**
     * Whether some *other* login, not this one, currently holds Super Admin
     * and can actually sign in with it right now.
     *
     * A locked or suspended Super Admin cannot exercise the role today, so
     * counting them would let the platform down to zero usable Super Admins
     * regardless — precisely the outcome this guard exists to prevent.
     */
    protected function anotherActiveSuperAdminExists(User $subject): bool
    {
        return User::role(PlatformRole::SuperAdmin->value)
            ->where('id', '!=', $subject->id)
            ->where('identity_status', UserStatus::Active->value)
            ->exists();
    }
}
