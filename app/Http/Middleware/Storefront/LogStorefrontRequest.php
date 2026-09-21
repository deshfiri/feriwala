<?php

namespace App\Http\Middleware\Storefront;

use App\Domain\Billing\PaymentLogRedactor;
use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\ApiLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every storefront call, written down as it was answered (contract §9, §42, P5-27).
 *
 * **Outermost**, so a request refused for its signature is recorded as surely as
 * one that succeeded — those are the requests an investigation is about.
 *
 * It gives each request its identifier before anything else runs, so the error
 * body a middleware further in builds carries the same identifier as the log
 * row, and a partner quoting it leads straight here.
 *
 * What is stored is redacted first by the same redactor the payment log uses
 * (§42): the `Authorization` header never reaches this table, and neither do
 * secrets or unmasked personal data in a query or a body.
 *
 * A log that fails to write never fails the request. The storefront asked a
 * question and deserves its answer; the failure is reported instead.
 */
class LogStorefrontRequest
{
    public const WEBSITE = 'storefront.website';

    public const CREDENTIAL = 'storefront.credential';

    public const KEY_ID = 'storefront.key_id';

    public function __construct(
        protected PaymentLogRedactor $redactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $requestId = (string) Str::ulid();

        $request->attributes->set(StorefrontError::REQUEST_ID, $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        $this->record($request, $response, $requestId, (int) ((hrtime(true) - $started) / 1_000_000));

        return $response;
    }

    protected function record(Request $request, Response $response, string $requestId, int $durationMs): void
    {
        $website = $request->attributes->get(self::WEBSITE);
        $credential = $request->attributes->get(self::CREDENTIAL);

        try {
            ApiLog::create([
                'request_id' => $requestId,
                'website_id' => $website instanceof Website ? $website->id : null,
                'website_credential_id' => $credential instanceof WebsiteCredential ? $credential->id : null,
                'key_id' => $request->attributes->get(self::KEY_ID),
                'method' => $request->getMethod(),
                'path' => mb_substr($request->getPathInfo(), 0, 255),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'error_code' => $request->attributes->get(StorefrontError::ERROR_CODE),
                'ip' => $request->ip(),
                'request_summary' => $this->redactor->redact([
                    'query' => $request->query(),
                    'body' => $this->body($request),
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::error('A storefront API request could not be logged.', [
                'request_id' => $requestId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The body as it may be kept.
     *
     * The shared redactor matches secrets by field name across every payload
     * it sees, and `code` is too common a name there — a gateway's error code,
     * a country code — to be one of them. Here it is exactly one thing: the
     * one-time code a customer confirms an order with (§6.2), and it is never
     * written down.
     *
     * @return array<array-key, mixed>
     */
    protected function body(Request $request): array
    {
        if (! $request->isJson()) {
            return [];
        }

        $body = (array) $request->json()->all();

        if (array_key_exists('code', $body)) {
            $body['code'] = PaymentLogRedactor::REDACTED;
        }

        return $body;
    }
}
