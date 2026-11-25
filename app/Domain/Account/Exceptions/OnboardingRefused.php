<?php

namespace App\Domain\Account\Exceptions;

use RuntimeException;

/**
 * An onboarding step the rules do not allow yet, refused before anything is
 * kept (D39). The message says what to do instead, in the reader's language,
 * and names the field it belongs to.
 */
class OnboardingRefused extends RuntimeException
{
    public function __construct(public readonly string $refusal, string $message, public readonly string $field = 'onboarding')
    {
        parent::__construct($message);
    }

    public static function because(string $refusal, string $field = 'onboarding'): self
    {
        return new self($refusal, __("onboarding.refused.{$refusal}"), $field);
    }
}
