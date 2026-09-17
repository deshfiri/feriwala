<?php

namespace App\Domain\Website\Api;

/**
 * The webhook signature, written down once (contract §7, P5-20).
 *
 *     X-Feriwala-Timestamp: <unix seconds>
 *     X-Feriwala-Signature: sha256=hex(HMAC-SHA256(secret, timestamp + "." + raw_body))
 *
 * **Both directions use this one definition.** The ERP signs every delivery it
 * sends with it, and {@see verify()} is the reference check a receiver runs —
 * the storefront application's own webhook handler, and any inbound webhook the
 * ERP accepts from a storefront in future. The other half of "both
 * directions", a storefront calling the ERP, is proved by the request signature
 * in {@see RequestSignature} (P5-18).
 *
 * A receiver must reject anything older than 300 seconds and compare in
 * constant time; `verify()` does both, and accepts a rotated secret while its
 * window is open.
 */
class WebhookSignature
{
    public const HEADER_EVENT = 'X-Feriwala-Event';

    public const HEADER_DELIVERY = 'X-Feriwala-Delivery';

    public const HEADER_TIMESTAMP = 'X-Feriwala-Timestamp';

    public const HEADER_SIGNATURE = 'X-Feriwala-Signature';

    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Whether a received webhook was signed by one of these secrets, recently.
     *
     * Every secret is tried whatever the first one answered, so the time this
     * takes says nothing about which was nearly right.
     *
     * @param  array<int, string>  $secrets
     */
    public static function verify(array $secrets, string $timestamp, string $body, string $signature, ?int $now = null): bool
    {
        if (preg_match('/^\d{9,11}$/', $timestamp) !== 1
            || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $matched = false;

        foreach ($secrets as $secret) {
            if (hash_equals(self::sign($secret, $timestamp, $body), strtolower($signature))) {
                $matched = true;
            }
        }

        return $matched;
    }
}
