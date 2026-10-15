<?php

declare(strict_types=1);

namespace Storefront\Erp;

/**
 * The request signature of contract §3.2, written once.
 *
 *     canonical = METHOD \n path+sorted query \n timestamp \n nonce \n sha256(body)
 *     signature = hex(HMAC-SHA256(secret, canonical))
 *
 * The query string is the raw string that goes on the wire, split on `&`, sorted
 * byte-wise and joined again — never decoded and re-encoded, because that is
 * where two correct implementations disagree about `%20` and `+`.
 */
final class Signature
{
    public static function canonical(string $method, string $path, string $rawQuery, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            $path.self::sortedQuery($rawQuery),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function authorization(string $keyId, string $signature): string
    {
        return 'Feriwala-HMAC-SHA256 Credential='.$keyId.', Signature='.$signature;
    }

    public static function nonce(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private static function sortedQuery(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $pairs = array_values(array_filter(explode('&', $rawQuery), static fn (string $pair) => $pair !== ''));
        sort($pairs, SORT_STRING);

        return '?'.implode('&', $pairs);
    }
}
