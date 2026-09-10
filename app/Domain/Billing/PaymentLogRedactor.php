<?php

namespace App\Domain\Billing;

/**
 * Strips what §42 forbids a log from holding.
 *
 * §42 lists it plainly: no passwords, no security tokens, no API secrets, no
 * gateway secrets, no complete payment credentials, no unmasked personal data.
 * A gateway payload contains several of those as a matter of course — the store
 * password is in every SSLCommerz request we send, the signature is in every IPN
 * we receive — so the payload is run through here rather than each caller being
 * asked to remember.
 *
 * **Deny by key, not by value.** Trying to recognise a secret by what it looks
 * like fails on the first credential that looks like an order number. Matching
 * the field name is crude and it holds.
 *
 * Personal data is **masked rather than dropped**: a support conversation about
 * a failed payment needs to know which number it went to, and the last four
 * digits answer that without the log becoming a phone book.
 */
class PaymentLogRedactor
{
    /**
     * Field names whose value never survives. Matched as substrings, so
     * `store_passwd`, `storePassword` and `password_confirmation` all go.
     *
     * @var array<int, string>
     */
    public const SECRET_KEYS = [
        'passwd', 'password', 'secret', 'token', 'api_key', 'apikey',
        'signature', 'verify_sign', 'verify_key', 'store_id', 'private',
        'authorization', 'auth_', 'credential', 'card', 'cvv', 'cvc',
        'pin', 'otp', 'session_key', 'sessionkey',
    ];

    /**
     * Field names kept but masked — enough to recognise, not enough to reuse.
     *
     * @var array<int, string>
     */
    public const MASKED_KEYS = ['phone', 'mobile', 'email', 'cus_phone', 'cus_email'];

    public const REDACTED = '[redacted]';

    /** Long values are truncated: a log is evidence, not a mirror. */
    public const MAX_LENGTH = 500;

    /** A payload with hundreds of fields is a payload somebody is probing with. */
    public const MAX_FIELDS = 60;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload, int $depth = 0): array
    {
        $clean = [];
        $seen = 0;

        foreach ($payload as $key => $value) {
            if (++$seen > self::MAX_FIELDS) {
                $clean['…'] = 'truncated';
                break;
            }

            $name = mb_strtolower((string) $key);

            if ($this->isSecret($name)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                // Bounded: a deeply nested payload is not worth recursing into
                // for ever, and nothing a gateway sends needs it.
                $clean[$key] = $depth >= 3 ? self::REDACTED : $this->redact($value, $depth + 1);

                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;

                continue;
            }

            $string = is_scalar($value) ? (string) $value : self::REDACTED;

            $clean[$key] = $this->isMasked($name) ? $this->mask($string) : $this->truncate($string);
        }

        return $clean;
    }

    /**
     * `01712345678` becomes `017****5678`; `a@b.com` keeps its domain.
     */
    public function mask(string $value): string
    {
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1).str_repeat('*', max(mb_strlen($local) - 1, 1)).'@'.$domain;
        }

        $length = mb_strlen($value);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 3).str_repeat('*', $length - 7).mb_substr($value, -4);
    }

    protected function isSecret(string $name): bool
    {
        foreach (self::SECRET_KEYS as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function isMasked(string $name): bool
    {
        foreach (self::MASKED_KEYS as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function truncate(string $value): string
    {
        return mb_strlen($value) <= self::MAX_LENGTH
            ? $value
            : mb_substr($value, 0, self::MAX_LENGTH).'…';
    }
}
