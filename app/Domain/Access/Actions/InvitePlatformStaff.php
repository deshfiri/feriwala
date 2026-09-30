<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Access\PlatformStaffInvited;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a brand-new platform staff login and hands it a role, all in one
 * step -- the "invite" half of Platform Staff management (commit-order item
 * 5). An administrator never chooses or views this person's password (§6):
 * a random, unusable one is stored so the `users.password` column's
 * not-null constraint is satisfied, and the real one is only ever set by
 * the invitee themselves, through the platform's own existing `web`-guard
 * password-reset broker and token table -- the same mechanism a "forgot
 * password" link already uses, not a bespoke invitation token.
 *
 * Role assignment itself is delegated whole to
 * {@see AssignPlatformRole}, so every one of its guards (the actor's own
 * authority ceiling, the last-active-Super-Admin protection) applies here
 * too rather than being re-implemented.
 */
class InvitePlatformStaff
{
    public function __construct(
        protected AssignPlatformRole $assignRole,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function handle(
        User $actor,
        string $name,
        string $email,
        ?string $mobile,
        array $roleNames,
        string $reason,
    ): User {
        if ($roleNames === []) {
            throw new InvalidArgumentException('A new platform staff member needs at least one role.');
        }

        // One transaction spanning the login and its role grant: a role
        // above the inviting actor's own authority, or any other guard
        // AssignPlatformRole enforces, must leave no stray, roleless user
        // behind -- not a half-finished invite nobody remembers exists.
        $user = $this->database->transaction(function () use ($actor, $name, $email, $mobile, $roleNames, $reason) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make(Str::random(40)),
            ]);

            /*
             * There is no self-registration step to verify this against
             * (§5.1's flow is a different scope entirely) -- an
             * already-authorized platform staff member typed this address in
             * and vouches for it. The password-setup link this class sends
             * next is the same proof-of-mailbox-ownership `HomeRoute` would
             * otherwise wait on, so leaving this null would only route every
             * new hire to `verification.notice` forever: nothing else ever
             * sends them a verification email to act on. `email_verified_at`
             * is deliberately not mass-assignable, so it is set here rather
             * than in the `create()` array above, which would silently drop
             * it.
             */
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->assignRole->handle($user, $actor, $roleNames, $reason);

            $this->audit->handle(new AuditEntry(
                action: 'access.staff_invited',
                actorId: $actor->id,
                auditableType: User::class,
                auditableId: $user->id,
                after: ['name' => $name, 'email' => $email, 'roles' => $roleNames],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));

            return $user;
        });

        $this->sendInvite($user);

        return $user;
    }

    /**
     * Send (or resend) the password-setup link. A previous, unused token is
     * left to expire on its own rather than revoked -- the broker's own
     * table already refuses more than one live token per email transparently
     * by simply issuing a new hash, and there is nothing sensitive in an
     * unused, unexpired reset token sitting next to one that superseded it.
     */
    public function sendInvite(User $user): void
    {
        $token = Password::broker('users')->createToken($user);

        $user->notify(new PlatformStaffInvited($token));
    }
}
