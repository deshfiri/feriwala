<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ChangeIdentityAccess;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Policies\UserPolicy;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Assigns a platform staff member exactly one of the twenty-one
 * {@see PlatformRole} cases (§32.1) -- the only role mechanism this
 * application has. There is no safe way to create or clone a custom role
 * without a code deploy, so this action's whole job is choosing among the
 * fixed twenty-one, never inventing a new one.
 *
 * Two rules refused here rather than in the policy, because `Gate::before`
 * passes a Super Admin actor straight over a policy method -- exactly the
 * pattern {@see ChangeIdentityAccess} already
 * uses for locking a login, and {@see UserPolicy::assignRole()}
 * carries the actor-facing half of the same two rules:
 *
 * **Nobody changes their own role.** A self-service escalation, or a
 * self-demotion nobody else asked for.
 *
 * **The platform's last Super Admin may never be demoted.** If theirs were
 * the only active login holding it, the recovery from that mistake is a
 * database console.
 */
class AssignPlatformRole
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(User $subject, User $actor, PlatformRole $role, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Assigning a role requires a recorded reason.');
        }

        if ($subject->is($actor)) {
            throw new InvalidArgumentException('A role cannot be changed for the person making the change.');
        }

        if ($subject->accountMembership()->exists()) {
            throw new InvalidArgumentException('Only a platform staff member may hold a platform role.');
        }

        $this->database->transaction(function () use ($subject, $role, $actor, $reason) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);

            $before = $locked->getRoleNames()->all();

            if (
                in_array(PlatformRole::SuperAdmin->value, $before, true)
                && $role !== PlatformRole::SuperAdmin
                && ! $this->anotherActiveSuperAdminExists($locked)
            ) {
                throw new InvalidArgumentException('The platform must always have at least one active Super Admin.');
            }

            $locked->syncRoles([$role->value]);

            $this->audit->handle(new AuditEntry(
                action: 'access.role_assigned',
                actorId: $actor->id,
                auditableType: User::class,
                auditableId: $subject->id,
                before: ['roles' => $before],
                after: ['roles' => [$role->value]],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));
        });
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
