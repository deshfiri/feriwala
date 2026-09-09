<?php

namespace App\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Login attempt limits and brute-force protection (§6).
 *
 * Two limits, because one attacker looks like two different things depending on
 * where you stand:
 *
 *   - **Per identity.** Guessing one person's password. Tight, because five
 *     wrong passwords for one account is already unusual, and because the cost
 *     of being wrong is one person waiting a minute.
 *   - **Per address.** Spraying one password across many accounts, which never
 *     trips a per-identity limit at all — each account sees a single failure.
 *     Looser and longer, because a whole office or a carrier NAT shares one
 *     address here and locking that out is an outage.
 *
 * Only **failed** authentication counts. A busy shared address signing people in
 * successfully never approaches the limit; thirty failures from one address in a
 * quarter of an hour is not what normal use looks like.
 *
 * Nothing here locks an account permanently. Both limits are cooldowns that
 * expire on their own — a permanent lock is a denial-of-service an attacker can
 * trigger against anyone whose email address they know, and §6 keeps locking as
 * a separate, deliberate administrative act (P1-17).
 */
class LoginThrottle extends LoginRateLimiter
{
    /**
     * Wrong passwords allowed against one identity before it cools off.
     *
     * The value Fortify has always applied here, kept rather than re-chosen.
     */
    public const IDENTITY_ATTEMPTS = 5;

    public const IDENTITY_DECAY_SECONDS = 60;

    /**
     * Failures allowed from one address, across every identity it tries.
     *
     * Deliberately generous and deliberately long: a shared address is the
     * normal case in Bangladesh, so this has to be high enough that an office
     * mistyping passwords never reaches it, and long enough that a script
     * working through a credential list cannot simply wait out a minute.
     */
    public const ADDRESS_ATTEMPTS = 30;

    public const ADDRESS_DECAY_SECONDS = 900;

    /**
     * Whether either limit is currently holding this request back.
     *
     * @param  Request  $request
     */
    public function tooManyAttempts($request): bool
    {
        return $this->limiter->tooManyAttempts($this->throttleKey($request), self::IDENTITY_ATTEMPTS)
            || $this->limiter->tooManyAttempts($this->addressKey($request), self::ADDRESS_ATTEMPTS);
    }

    /**
     * Record one failed authentication against both limits.
     *
     * @param  Request  $request
     */
    public function increment($request): void
    {
        $this->limiter->hit($this->throttleKey($request), self::IDENTITY_DECAY_SECONDS);
        $this->limiter->hit($this->addressKey($request), self::ADDRESS_DECAY_SECONDS);
    }

    /**
     * Seconds until whichever limit is holding this request lets go.
     *
     * @param  Request  $request
     */
    public function availableIn($request): int
    {
        return max(
            $this->waitFor($this->throttleKey($request), self::IDENTITY_ATTEMPTS),
            $this->waitFor($this->addressKey($request), self::ADDRESS_ATTEMPTS),
        );
    }

    /**
     * Forget the failures against this identity, and only this identity.
     *
     * Called on a successful sign-in. The address limit deliberately survives:
     * an attacker working through a credential list will eventually land on a
     * real password, and clearing the address bucket there would hand them a
     * fresh budget for the rest of the list — the one moment protection matters
     * most is the moment this would switch it off.
     *
     * @param  Request  $request
     */
    public function clear($request): void
    {
        $this->limiter->clear($this->throttleKey($request));
    }

    /**
     * Failures recorded against the requesting address.
     *
     * @param  Request  $request
     */
    public function attemptsFromAddress($request): int
    {
        return (int) $this->limiter->attempts($this->addressKey($request));
    }

    /**
     * The email as both the limiter and authentication see it.
     *
     * They have to agree. The throttle key is built before Fortify canonicalizes
     * the username — `EnsureLoginIsNotThrottled` runs first in the pipeline — so
     * if this normalized differently, `USER@Example.com` and `user@example.com`
     * would be one account with two separate attempt budgets, and five tries
     * would become ten. `TrimStrings` has already removed the whitespace by the
     * time either sees it; `fortify.lowercase_usernames` does the rest.
     */
    public static function normalize(mixed $username): string
    {
        return is_string($username) ? Str::lower(trim($username)) : '';
    }

    /**
     * Seconds left on a limit, or zero when that limit is not the one blocking.
     */
    protected function waitFor(string $key, int $maxAttempts): int
    {
        return $this->limiter->tooManyAttempts($key, $maxAttempts)
            ? $this->limiter->availableIn($key)
            : 0;
    }

    /**
     * The per-identity key.
     *
     * Hashed, for two reasons. A Redis keyspace listing should not enumerate the
     * addresses people have been trying to sign in with, the same care one-time
     * codes already take. And a hash is unambiguous where transliteration is
     * not: folding accents to ASCII would quietly put two different accounts in
     * one bucket, letting either lock the other out.
     *
     * @param  Request  $request
     */
    protected function throttleKey($request): string
    {
        return 'login:identity:'.hash(
            'sha256',
            self::normalize($request->input(Fortify::username())),
        );
    }

    /**
     * @param  Request  $request
     */
    protected function addressKey($request): string
    {
        return 'login:address:'.hash('sha256', (string) $request->ip());
    }
}
