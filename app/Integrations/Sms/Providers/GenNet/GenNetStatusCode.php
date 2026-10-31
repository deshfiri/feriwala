<?php

namespace App\Integrations\Sms\Providers\GenNet;

/**
 * GenNet's API status codes (API documentation v3.0.0, §3.1), in words an
 * administrator can act on.
 *
 * Only two are worth another attempt: a request limit and a TPS limit are
 * refusals made *before* anything is sent. Everything else will be refused
 * the same way again, and an unknown outcome is never retried (see
 * {@see GenNetSmsProvider}).
 */
final class GenNetStatusCode
{
    /** @var array<int, string> */
    protected const MESSAGES = [
        4001 => 'GenNet rejected the API token.',
        4002 => 'This sender ID (SID) is not permitted to send SMS.',
        4003 => 'This server\'s IP address is blacklisted by GenNet.',
        4004 => 'GenNet reported the endpoint was not found — check the base address.',
        4005 => 'GenNet could not read the request (invalid content type or JSON).',
        4008 => 'The sender ID has insufficient SMS balance.',
        4020 => 'GenNet rejected the message reference (CSMS ID).',
        4022 => 'A required parameter was missing from the request.',
        4023 => 'GenNet saw this message reference (CSMS ID) already today.',
        4025 => 'The mobile number is invalid.',
        4026 => 'The mobile number is blocked.',
        4027 => 'The message is too long.',
        4028 => 'None of the messages in the request were valid.',
        4029 => 'GenNet\'s request limit was exceeded.',
        4030 => 'Too many numbers in one request.',
        4031 => 'GenNet\'s transactions-per-second limit was exceeded.',
        5000 => 'GenNet reported an unknown error.',
    ];

    /** Refused before sending: asking again later can succeed. */
    protected const RETRYABLE = [4029, 4031];

    public static function describe(int $code, ?string $providerMessage = null): string
    {
        $known = self::MESSAGES[$code] ?? null;

        if ($known === null) {
            return $providerMessage !== null && $providerMessage !== ''
                ? "GenNet error {$code}: {$providerMessage}"
                : "GenNet error {$code}.";
        }

        return $known;
    }

    public static function isRetryable(int $code): bool
    {
        return in_array($code, self::RETRYABLE, true);
    }
}
