<?php

namespace App\Integrations\Sms\Exceptions;

use App\Integrations\Sms\Data\SmsResult;
use RuntimeException;

/**
 * The provider could not be reached, answered incomprehensibly, or has no
 * credentials configured.
 *
 * Distinct from a provider-side rejection ({@see SmsResult}),
 * which is a result, never an exception -- this means the message could not be
 * attempted at all.
 */
class SmsProviderUnavailable extends RuntimeException
{
    public static function forProvider(string $provider, string $detail): self
    {
        return new self("The {$provider} SMS provider could not be reached: {$detail}");
    }

    public static function malformedResponse(string $provider): self
    {
        return new self(
            "The {$provider} SMS provider returned a response that could not be understood."
        );
    }

    public static function missingCredentials(string $provider): self
    {
        return new self(
            "No credentials are configured for the {$provider} SMS provider. "
            .'Add them in settings — they are never read from code or committed config.'
        );
    }
}
