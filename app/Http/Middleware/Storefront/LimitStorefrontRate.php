<?php

namespace App\Http\Middleware\Storefront;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\WebsiteCredential;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-credential rate limits, counted where every server can see them
 * (contract §4.6, P5-19).
 *
 * Three classes — catalogue and inventory reads, order and customer writes,
 * everything else — each with its own budget per minute. Counted per
 * **credential**, not per IP: a storefront sits behind one address for all of
 * its customers, and one partner's traffic must never spend another's budget.
 *
 * The limiter store is Redis in production (config/cache.php pins it), because
 * a limit counted separately on each application server is not a limit.
 *
 * Every response carries its budget, so a well-behaved storefront can slow down
 * before it is told to stop.
 */
class LimitStorefrontRate
{
    public function __construct(
        protected RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next, string $class = 'other'): Response
    {
        $credential = $request->attributes->get(LogStorefrontRequest::CREDENTIAL);

        if (! $credential instanceof WebsiteCredential) {
            return $next($request);
        }

        $limit = (int) config('website.api.rate_limits.'.$class, config('website.api.rate_limits.other', 300));
        $key = 'storefront:'.$credential->id.':'.$class;

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            $retryAfter = $this->limiter->availableIn($key);

            return StorefrontError::respond(
                $request,
                429,
                'rate_limited',
                'Too many requests. Retry after the time given in Retry-After.',
                ['retry_after' => $retryAfter],
                $this->headers($limit, 0, $retryAfter) + ['Retry-After' => (string) $retryAfter],
            );
        }

        $this->limiter->hit($key, 60);

        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($limit, $this->limiter->remaining($key, $limit), $this->limiter->availableIn($key)) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(int $limit, int $remaining, int $resetIn): array
    {
        return [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) max(0, $remaining),
            'X-RateLimit-Reset' => (string) (time() + $resetIn),
        ];
    }
}
