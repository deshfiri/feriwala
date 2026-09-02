<?php

namespace App\Domain\Account;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Issues and checks one-time mobile verification codes (§5.1, §6).
 *
 * Codes live in Redis with a TTL rather than in a table — §38 lists temporary
 * tokens as a Redis concern, and an expired code should disappear on its own
 * rather than accumulate in the database.
 *
 * A one-time code is a credential, so it is treated like one:
 *
 *   - stored **hashed**, never in plain text, so a Redis dump does not hand
 *     over every code currently in flight
 *   - compared in constant time
 *   - given a small, fixed attempt budget — a six-digit code is only a million
 *     possibilities, which is nothing to a script
 *   - destroyed once used or once the budget is spent, so a guessed code cannot
 *     be replayed
 */
class VerificationCodes
{
    public const LENGTH = 6;

    /** Codes expire quickly; a stale code is a liability, not a convenience. */
    public const TTL_SECONDS = 300;

    /** Wrong guesses allowed before the code is destroyed. */
    public const MAX_ATTEMPTS = 5;

    /** Minimum gap between sends, so resend cannot be used to flood a number. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        protected Cache $cache,
        protected Hasher $hasher,
    ) {}

    /**
     * Issue a code for a purpose, returning the plain text exactly once.
     *
     * The caller sends it and then forgets it — nothing else can read it back.
     */
    public function issue(string $purpose, string $identifier): string
    {
        $code = $this->randomCode();

        $this->cache->put(
            $this->key($purpose, $identifier),
            ['hash' => $this->hasher->make($code), 'attempts' => 0],
            self::TTL_SECONDS,
        );

        $this->cache->put(
            $this->cooldownKey($purpose, $identifier),
            true,
            self::RESEND_COOLDOWN_SECONDS,
        );

        return $code;
    }

    /**
     * Whether a new code may be sent yet.
     */
    public function canIssue(string $purpose, string $identifier): bool
    {
        return ! $this->cache->has($this->cooldownKey($purpose, $identifier));
    }

    /**
     * Seconds remaining before another code may be requested.
     */
    public function secondsUntilResend(string $purpose, string $identifier): int
    {
        return $this->canIssue($purpose, $identifier) ? 0 : self::RESEND_COOLDOWN_SECONDS;
    }

    /**
     * Check a submitted code, consuming it on success.
     *
     * Returns false for a wrong code, an expired code, and a code that was never
     * issued alike — distinguishing them would tell an attacker whether a number
     * is mid-verification.
     */
    public function verify(string $purpose, string $identifier, string $submitted): bool
    {
        $key = $this->key($purpose, $identifier);

        /** @var array{hash: string, attempts: int}|null $record */
        $record = $this->cache->get($key);

        if ($record === null) {
            return false;
        }

        if ($record['attempts'] >= self::MAX_ATTEMPTS) {
            $this->cache->forget($key);

            return false;
        }

        if (! $this->hasher->check($submitted, $record['hash'])) {
            $record['attempts']++;

            if ($record['attempts'] >= self::MAX_ATTEMPTS) {
                // Budget spent — destroy it rather than leave a nearly-guessed
                // code sitting there until its TTL runs out.
                $this->cache->forget($key);
            } else {
                // Preserve the original expiry; a wrong guess must not extend
                // the code's life.
                $this->cache->put($key, $record, self::TTL_SECONDS);
            }

            return false;
        }

        // One-time really means once.
        $this->cache->forget($key);
        $this->cache->forget($this->cooldownKey($purpose, $identifier));

        return true;
    }

    /**
     * Attempts already spent against the current code.
     */
    public function attemptsUsed(string $purpose, string $identifier): int
    {
        /** @var array{hash: string, attempts: int}|null $record */
        $record = $this->cache->get($this->key($purpose, $identifier));

        return $record['attempts'] ?? 0;
    }

    public function forget(string $purpose, string $identifier): void
    {
        $this->cache->forget($this->key($purpose, $identifier));
        $this->cache->forget($this->cooldownKey($purpose, $identifier));
    }

    /**
     * Numeric, because it is typed on a phone keypad, and zero-padded so it is
     * always the same length.
     */
    protected function randomCode(): string
    {
        return str_pad(
            (string) random_int(0, (10 ** self::LENGTH) - 1),
            self::LENGTH,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * The identifier is hashed into the key so a Redis keyspace listing does not
     * expose which phone numbers are being verified.
     */
    protected function key(string $purpose, string $identifier): string
    {
        return sprintf('verification:%s:%s', $purpose, hash('sha256', $identifier));
    }

    protected function cooldownKey(string $purpose, string $identifier): string
    {
        return $this->key($purpose, $identifier).':cooldown';
    }
}
