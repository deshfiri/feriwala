<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * A sensitive action was attempted without satisfying §32.2's escalation.
 *
 * Distinct from a plain authorization failure: the person may well hold the
 * permission, but has not confirmed their identity recently, or has not given
 * the reason the action requires.
 */
class SensitiveActionRefused extends RuntimeException
{
    public static function notPermitted(string $permission): self
    {
        return new self("You do not have permission to perform [{$permission}].");
    }

    public static function needsConfirmation(string $permission): self
    {
        return new self(
            "[{$permission}] requires you to confirm your password before continuing."
        );
    }

    public static function needsTwoFactor(string $permission): self
    {
        return new self(
            "[{$permission}] requires two-factor authentication to be enabled on your account."
        );
    }

    public static function needsReason(string $permission): self
    {
        return new self(
            "[{$permission}] requires a reason. It is recorded in the audit log."
        );
    }

    public static function needsSecondApprover(string $permission): self
    {
        return new self(
            "[{$permission}] requires approval from a second authorised person."
        );
    }
}
