<?php

declare(strict_types=1);

namespace Storefront\Http;

/**
 * One token per session, checked on every state-changing request.
 *
 * Compared in constant time. A page that needs it renders `field()`; a
 * `fetch()` sends it as `X-CSRF-Token`.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');

        if (! is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf', $token);
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="'.htmlspecialchars(self::token(), ENT_QUOTES).'">';
    }

    public static function valid(Request $request): bool
    {
        $given = $request->header('x-csrf-token') ?? $request->string('_token');
        $expected = Session::get('_csrf');

        return is_string($expected) && $given !== '' && hash_equals($expected, $given);
    }
}
