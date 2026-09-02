<?php

namespace App\Domain\Access;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Enforces the §32.2 escalation before a sensitive action runs.
 *
 * Holding a permission answers "may this person do this". This answers "is it
 * really them, right now, and do they mean it" — which is a different question,
 * and the one that matters when a session has been left open on a shared machine
 * or a password has leaked.
 *
 * Called from inside the Action that performs the work, not only from a
 * controller, so the check cannot be skipped by reaching the Action from a job,
 * a command, or a second controller.
 */
class SensitiveActionGuard
{
    /**
     * Throw unless every applicable control is satisfied.
     *
     * Checks run in order of what the user can fix soonest: permission first
     * (nothing they can do), then identity, then intent. A user missing three
     * things is told about the one that matters first.
     *
     * @throws SensitiveActionRefused
     */
    public function authorize(
        ?Authenticatable $user,
        SensitiveActionRequest $request,
    ): void {
        $permission = $request->permission();

        if (! $user instanceof Authorizable || ! $user->can($permission)) {
            throw SensitiveActionRefused::notPermitted($permission);
        }

        if (! $request->isSensitive()) {
            return;
        }

        if (! $request->passwordConfirmed) {
            throw SensitiveActionRefused::needsConfirmation($permission);
        }

        if ($request->requiresTwoFactor() && ! $request->twoFactorEnabled) {
            throw SensitiveActionRefused::needsTwoFactor($permission);
        }

        if ($request->requiresSecondApprover() && ! $request->secondApproverGranted) {
            throw SensitiveActionRefused::needsSecondApprover($permission);
        }

        if ($request->requiresReason() && blank($request->reason)) {
            throw SensitiveActionRefused::needsReason($permission);
        }
    }

    /**
     * Whether the action would be allowed, without throwing.
     *
     * For deciding whether to render a control. The UI asking this is a
     * convenience; {@see authorize()} inside the Action is the enforcement.
     */
    public function allows(
        ?Authenticatable $user,
        SensitiveActionRequest $request,
    ): bool {
        try {
            $this->authorize($user, $request);

            return true;
        } catch (SensitiveActionRefused) {
            return false;
        }
    }
}
