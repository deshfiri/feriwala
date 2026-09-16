<?php

namespace App\Http\Middleware\Storefront;

use App\Domain\Website\Api\RequestSignature;
use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is calling, proved rather than claimed (contract §3.2, §3.3, §3.5, P5-18).
 *
 * All of these, in this order, or nothing:
 *
 *   1. **HTTPS.** Plain HTTP is refused, never redirected — a redirect would
 *      already have sent the signed request in the clear (§3.3).
 *   2. **A credential that exists and has not been revoked.**
 *   3. **A timestamp within ±300 seconds** of this server's clock.
 *   4. **A signature that matches**, compared in constant time, against the
 *      current secret or a rotated one still inside its window.
 *   5. **A nonce not seen for this credential in 600 seconds.** Checked last,
 *      after the signature, so somebody without the secret cannot burn a
 *      storefront's nonces by sending garbage.
 *
 * Every failure is the same `401 unauthenticated` to the caller, and a
 * different, precise reason in `api_logs`. Telling a caller *which* check failed
 * helps nobody but somebody working out what to forge next; telling the person
 * reading the log is the point.
 *
 * Nonces are held as locks, which in production live on the dedicated Redis
 * locks database (§38): a cache flush must not reopen a replay window.
 *
 * **The credential is the tenancy boundary** (§3.5). The website it belongs to
 * is bound to the request here, and every controller behind this reads its
 * website from there and from nowhere else. No endpoint accepts a website from
 * the caller.
 */
class AuthenticateStorefrontRequest
{
    public function __construct(
        protected CacheFactory $cache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure()) {
            return StorefrontError::respond($request, 400, 'https_required', 'The storefront API is served over HTTPS only.');
        }

        $authorization = RequestSignature::parseAuthorization($request->header(RequestSignature::HEADER_AUTHORIZATION));

        if ($authorization === null) {
            return $this->refuse($request, 'missing_or_malformed_authorization');
        }

        $request->attributes->set(LogStorefrontRequest::KEY_ID, $authorization['key_id']);

        /** @var WebsiteCredential|null $credential */
        $credential = WebsiteCredential::query()
            ->with('website')
            ->where('key_id', $authorization['key_id'])
            ->first();

        if ($credential === null) {
            return $this->refuse($request, 'unknown_credential');
        }

        $request->attributes->set(LogStorefrontRequest::CREDENTIAL, $credential);
        $request->attributes->set(LogStorefrontRequest::WEBSITE, $credential->website);

        if ($credential->isRevoked()) {
            return $this->refuse($request, 'credential_revoked');
        }

        $now = CarbonImmutable::now();

        if (! $this->timestampIsFresh((string) $request->header(RequestSignature::HEADER_TIMESTAMP, ''), $now)) {
            return $this->refuse($request, 'stale_timestamp');
        }

        $nonce = (string) $request->header(RequestSignature::HEADER_NONCE, '');

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $nonce) !== 1) {
            return $this->refuse($request, 'malformed_nonce');
        }

        if (! RequestSignature::matches(
            $credential->acceptedSecrets($now),
            RequestSignature::canonicalFor($request),
            $authorization['signature'],
        )) {
            return $this->refuse($request, 'invalid_signature');
        }

        if (! $this->claimNonce($credential, $nonce)) {
            return $this->refuse($request, 'replayed_nonce');
        }

        if (! $this->websiteIsServing($credential->website)) {
            return StorefrontError::respond(
                $request,
                403,
                'website_unavailable',
                'This website is not currently being served.',
            );
        }

        $this->noteUse($credential, $now);

        return $next($request);
    }

    protected function refuse(Request $request, string $reason): Response
    {
        $response = StorefrontError::respond(
            $request,
            401,
            'unauthenticated',
            'The request could not be authenticated.',
        );

        // The precise reason is for the log, not for the caller.
        $request->attributes->set(StorefrontError::ERROR_CODE, $reason);

        return $response;
    }

    protected function timestampIsFresh(string $timestamp, CarbonImmutable $now): bool
    {
        if (preg_match('/^\d{9,11}$/', $timestamp) !== 1) {
            return false;
        }

        return abs($now->getTimestamp() - (int) $timestamp) <= (int) config('website.api.clock_skew_seconds', 300);
    }

    /**
     * Take the nonce for this credential, or learn it was already taken.
     *
     * A lock acquired and never released is a set-if-absent with an expiry,
     * which is exactly what a replay window is.
     */
    protected function claimNonce(WebsiteCredential $credential, string $nonce): bool
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            // A store that cannot hold a lock cannot hold a replay window, and
            // accepting every nonce would be pretending otherwise.
            return false;
        }

        return $store
            ->lock('storefront-nonce:'.$credential->id.':'.strtolower($nonce), (int) config('website.api.nonce_ttl_seconds', 600))
            ->get();
    }

    /**
     * Whether this website's storefront is allowed to call at all.
     *
     * A shop being built needs its catalogue to build against, and one under
     * maintenance is being worked on; a suspended, disabled, lapsed or closed
     * one is not served and its credentials stop working with it.
     */
    protected function websiteIsServing(Website $website): bool
    {
        return $website->status->isLive()
            || in_array($website->status, [
                WebsiteStatus::Development,
                WebsiteStatus::ApiConnectionPending,
                WebsiteStatus::Maintenance,
            ], true);
    }

    /**
     * Remember that the credential was used, without a write on every call.
     */
    protected function noteUse(WebsiteCredential $credential, CarbonImmutable $now): void
    {
        if ($credential->last_used_at === null || $credential->last_used_at->diffInSeconds($now) >= 60) {
            WebsiteCredential::query()->whereKey($credential->id)->update(['last_used_at' => $now]);
        }

        if ($credential->website->api_connected_at === null) {
            Website::query()->whereKey($credential->website_id)->update(['api_connected_at' => $now]);
        }
    }
}
