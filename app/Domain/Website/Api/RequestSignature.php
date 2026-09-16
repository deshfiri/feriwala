<?php

namespace App\Domain\Website\Api;

use Illuminate\Http\Request;

/**
 * The storefront signature, written down once (contract §3.2, P5-18).
 *
 *     canonical_request =
 *         <HTTP-METHOD>                  \n
 *         <path + sorted query string>   \n
 *         <X-Feriwala-Timestamp>         \n
 *         <X-Feriwala-Nonce>             \n
 *         <hex sha256 of the raw body; empty string hashed when there is none>
 *
 *     signature = hex(HMAC-SHA256(secret, canonical_request))
 *
 * **Sorted query string** means the raw query string split on `&`, the pairs
 * sorted byte-wise, and joined again — exactly as they were encoded on the wire,
 * never decoded and re-encoded. Re-encoding is where two correct
 * implementations quietly disagree about `%20` and `+`, and a signature scheme
 * that fails on the first space is one every storefront works around.
 *
 * Used by the verifier here and by anything that has to produce a signature —
 * the tests, and the storefront application's client — so the two cannot drift.
 */
class RequestSignature
{
    public const HEADER_AUTHORIZATION = 'Authorization';

    public const HEADER_TIMESTAMP = 'X-Feriwala-Timestamp';

    public const HEADER_NONCE = 'X-Feriwala-Nonce';

    public const SCHEME = 'Feriwala-HMAC-SHA256';

    /**
     * The canonical request for a set of parts.
     */
    public static function canonical(
        string $method,
        string $path,
        string $rawQuery,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path.self::sortedQuery($rawQuery),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /**
     * The canonical request an incoming request describes.
     */
    public static function canonicalFor(Request $request): string
    {
        $rawQuery = $request->server('QUERY_STRING', '');

        return self::canonical(
            $request->getMethod(),
            $request->getPathInfo(),
            is_string($rawQuery) ? $rawQuery : '',
            (string) $request->header(self::HEADER_TIMESTAMP, ''),
            (string) $request->header(self::HEADER_NONCE, ''),
            (string) $request->getContent(),
        );
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Whether any of the accepted secrets produced this signature.
     *
     * Compared in constant time, and every secret is tried whatever the first
     * one answered, so how long this takes says nothing about which secret was
     * nearly right.
     *
     * @param  array<int, string>  $secrets
     */
    public static function matches(array $secrets, string $canonical, string $signature): bool
    {
        $matched = false;

        foreach ($secrets as $secret) {
            if (hash_equals(self::sign($secret, $canonical), strtolower($signature))) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * The key identifier and signature an `Authorization` header carries.
     *
     * @return array{key_id: string, signature: string}|null
     */
    public static function parseAuthorization(?string $header): ?array
    {
        if ($header === null || ! str_starts_with($header, self::SCHEME.' ')) {
            return null;
        }

        $matched = preg_match(
            '/^'.preg_quote(self::SCHEME, '/').' Credential=(wsk_[0-9A-Z]{26}), Signature=([0-9a-fA-F]{64})$/',
            $header,
            $parts,
        );

        return $matched === 1 ? ['key_id' => $parts[1], 'signature' => $parts[2]] : null;
    }

    /**
     * The header a caller sends.
     */
    public static function authorization(string $keyId, string $signature): string
    {
        return self::SCHEME.' Credential='.$keyId.', Signature='.$signature;
    }

    protected static function sortedQuery(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $pairs = array_values(array_filter(explode('&', $rawQuery), fn (string $pair) => $pair !== ''));
        sort($pairs, SORT_STRING);

        return '?'.implode('&', $pairs);
    }
}
