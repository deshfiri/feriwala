<?php

namespace App\Integrations\Sms\Data;

/**
 * What a provider did with a message.
 *
 * A rejection is a result rather than an exception: an invalid number or an
 * exhausted balance will not succeed on retry, and the queue should record it
 * instead of retrying forever.
 */
class SmsResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerReference = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $cost = null,
        public readonly bool $retryable = false,
    ) {}

    public static function accepted(?string $reference = null, ?string $cost = null): self
    {
        return new self(accepted: true, providerReference: $reference, cost: $cost);
    }

    /**
     * The provider refused and will refuse again — a malformed number, a
     * blocked sender, no balance.
     */
    public static function rejected(string $error, ?string $code = null): self
    {
        return new self(accepted: false, error: $error, errorCode: $code, retryable: false);
    }

    /**
     * Something transient — a timeout, a 5xx. Worth another attempt.
     */
    public static function failed(string $error, ?string $code = null): self
    {
        return new self(accepted: false, error: $error, errorCode: $code, retryable: true);
    }
}
