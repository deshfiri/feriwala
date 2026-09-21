<?php

namespace App\Http\Middleware\Storefront;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\StorefrontRequest;
use App\Domain\Website\Models\Website;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every write answered once per key, per website (contract §4.7, §17.3, P5-21).
 *
 *   - first use of a key       → the request runs, and its answer is kept;
 *   - the same key, same body  → the answer it was given, byte for byte;
 *   - the same key, other body → `409 idempotency_key_reused`.
 *
 * The key is scoped to the website, so two storefronts choosing the same key
 * never meet. Concurrent retries queue on a lock and the second replays the
 * first's answer rather than running again; the unique index is underneath, so
 * even a lost lock cannot store two answers for one key.
 *
 * Server faults are not kept — a `5xx` is a request that may not have happened,
 * and a storefront retrying one must be able to reach a different outcome.
 */
class ReplayStorefrontWrite
{
    /** How long one write may hold its key. */
    protected const LOCK_TTL = 45;

    /** How long a concurrent retry waits for the first to finish. */
    protected const LOCK_WAIT = 15;

    public function __construct(
        protected DistributedLock $lock,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $website = $request->attributes->get(LogStorefrontRequest::WEBSITE);
        $key = trim((string) $request->header('Idempotency-Key'));

        if (! $website instanceof Website) {
            return $next($request);
        }

        if ($key === '' || mb_strlen($key) > 64) {
            return StorefrontError::respond(
                $request,
                400,
                'idempotency_key_required',
                'Every write needs an Idempotency-Key header of up to 64 characters.',
            );
        }

        /*
         * Keyed, not a bare hash. A body can carry a one-time confirmation code
         * (§6.2), and a plain SHA-256 of `{"code":"123456"}` is recovered by
         * trying all million candidates in a moment by anybody who can read
         * this table. Keyed with the application key, the stored value says
         * whether two requests were the same and nothing about what they said.
         */
        $fingerprint = hash_hmac('sha256', implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getContent(),
        ]), (string) config('app.key'));

        if (($kept = $this->kept($website, $key)) !== null) {
            return $this->replay($request, $kept, $fingerprint);
        }

        try {
            return $this->lock->run(
                key: 'storefront:write:'.$website->id.':'.$key,
                callback: function () use ($request, $next, $website, $key, $fingerprint) {
                    if (($kept = $this->kept($website, $key)) !== null) {
                        return $this->replay($request, $kept, $fingerprint);
                    }

                    /** @var Response $response */
                    $response = $next($request);

                    $this->keep($website, $request, $key, $fingerprint, $response);

                    return $response;
                },
                ttlSeconds: self::LOCK_TTL,
                waitSeconds: self::LOCK_WAIT,
            );
        } catch (LockTimeout) {
            return StorefrontError::respond(
                $request,
                409,
                'request_in_progress',
                'A request with this idempotency key is still being processed. Retry shortly.',
            );
        }
    }

    protected function kept(Website $website, string $key): ?StorefrontRequest
    {
        /** @var StorefrontRequest|null $kept */
        $kept = StorefrontRequest::query()
            ->where('website_id', $website->id)
            ->where('idempotency_key', $key)
            ->first();

        return $kept;
    }

    protected function replay(Request $request, StorefrontRequest $kept, string $fingerprint): Response
    {
        if (! hash_equals($kept->fingerprint, $fingerprint)) {
            return StorefrontError::respond(
                $request,
                409,
                'idempotency_key_reused',
                'This idempotency key was used for a different request.',
            );
        }

        return new Response($kept->response_body, $kept->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replay' => 'true',
        ]);
    }

    protected function keep(Website $website, Request $request, string $key, string $fingerprint, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 500 || $status === 401 || $status === 403 || $status === 429) {
            return;
        }

        try {
            StorefrontRequest::create([
                'website_id' => $website->id,
                'idempotency_key' => $key,
                'method' => $request->getMethod(),
                'path' => mb_substr($request->getPathInfo(), 0, 255),
                'fingerprint' => $fingerprint,
                'response_status' => $status,
                'response_body' => (string) $response->getContent(),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request stored the answer for this key first; it is the
            // same answer, and the next replay will read theirs.
        }
    }
}
