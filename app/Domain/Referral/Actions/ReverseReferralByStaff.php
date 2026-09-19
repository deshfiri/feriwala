<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Models\User;

/**
 * A person taking referral commission back (§32.2, D24, P7-44).
 *
 * `referral.reverse_transaction` is a sensitive permission: the §32.2
 * escalation — a freshly confirmed password, two-factor and a reason — is
 * enforced here, inside the action, so no second route to it can skip it.
 */
class ReverseReferralByStaff
{
    public function __construct(
        protected SensitiveActionGuard $guard,
        protected ReverseReferralCommission $commission,
        protected ReverseQualifyingEvent $event,
    ) {}

    /**
     * @throws SensitiveActionRefused
     * @throws ReferralRefused
     */
    public function commission(ReferralCommission $commission, User $actor, ReversalCause $cause, string $reason, bool $passwordConfirmed): ReferralCommission
    {
        $this->authorize($actor, $reason, $passwordConfirmed);

        return $this->commission->handle($commission, $cause, $reason, $actor);
    }

    /**
     * @throws SensitiveActionRefused
     */
    public function event(ReferralQualifyingEvent $event, User $actor, ReversalCause $cause, string $reason, bool $passwordConfirmed): ReferralQualifyingEvent
    {
        $this->authorize($actor, $reason, $passwordConfirmed);

        return $this->event->handle($event, $cause, $reason, $actor);
    }

    /**
     * @throws SensitiveActionRefused
     */
    protected function authorize(User $actor, string $reason, bool $passwordConfirmed): void
    {
        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Referral,
            action: PermissionAction::ReverseTransaction,
            reason: $reason,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
        ));
    }
}
