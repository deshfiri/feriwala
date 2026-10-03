<?php

namespace App\Domain\Access\Data;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;

/**
 * A sensitive action about to be attempted, and what it must satisfy.
 *
 * §32.2 lists five escalation controls — password confirmation, two-factor,
 * additional approval, a mandatory reason, and audit logging. Which apply is a
 * property of the action, declared here, rather than something each call site
 * remembers to check.
 */
class SensitiveActionRequest
{
    public function __construct(
        public readonly PermissionModule $module,
        public readonly PermissionAction $action,
        public readonly ?string $reason = null,
        public readonly bool $passwordConfirmed = false,
        public readonly bool $twoFactorEnabled = false,
        public readonly bool $secondApproverGranted = false,
    ) {}

    public function permission(): string
    {
        return $this->module->value.'.'.$this->action->value;
    }

    /**
     * Whether this action needs the §32.2 escalation at all.
     */
    public function isSensitive(): bool
    {
        return $this->action->isSensitive();
    }

    /**
     * Actions that move money or release funds need two-factor on top of a
     * confirmed password. A stolen session is enough to satisfy a password
     * prompt if the password is also known; a second factor is not.
     *
     * `ManageIntegrations` joined this list for the Cloudflare R2 storage
     * credentials (beta-critical batch, Commit 3): a leaked integration
     * secret (an access key, a webhook signing key) is as consequential as a
     * wallet adjustment, since it is standing access rather than a one-time
     * transaction. This applies to every `*.manage_integrations` permission,
     * not only storage -- the verb was already password-confirmed and
     * audited for all of them, and two-factor is a strengthening of an
     * existing control, never a new one.
     */
    public function requiresTwoFactor(): bool
    {
        return in_array($this->action, [
            PermissionAction::ReleasePayment,
            PermissionAction::AdjustWallet,
            PermissionAction::ReverseTransaction,
            PermissionAction::SettleManually,
            PermissionAction::ManageBackups,
            PermissionAction::ManageIntegrations,
        ], true);
    }

    /**
     * Actions where a second person must approve — the maker-checker control.
     *
     * Releasing a payout is the clearest case: one person should not be able to
     * both authorise and execute money leaving the platform.
     */
    public function requiresSecondApprover(): bool
    {
        return $this->action === PermissionAction::ReleasePayment;
    }

    /**
     * Every sensitive action records why it was taken. "Who did what" without
     * "why" leaves an investigator guessing at intent.
     */
    public function requiresReason(): bool
    {
        return $this->isSensitive();
    }

    public function withReason(?string $reason): self
    {
        return new self(
            module: $this->module,
            action: $this->action,
            reason: $reason,
            passwordConfirmed: $this->passwordConfirmed,
            twoFactorEnabled: $this->twoFactorEnabled,
            secondApproverGranted: $this->secondApproverGranted,
        );
    }
}
