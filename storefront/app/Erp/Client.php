<?php

namespace App\Erp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Talks to the Feriwala storefront API (contract v1).
 *
 * Every request is signed. Reads and writes are retried on `429` and `5xx` with
 * exponential backoff and jitter — and only those; a `4xx` is an answer, not a
 * fault (§8.4). Each attempt is a fresh request with a fresh nonce and
 * timestamp, because a nonce is accepted once. Every write carries an
 * `Idempotency-Key`, which is what makes that retry safe (§4.7).
 *
 * The transport is Laravel's HTTP client, so the whole client can be exercised
 * with `Http::fake()`.
 */
final class Client
{
    private const PREFIX = '/api/storefront/v1';

    /**
     * @param  list<int>  $backoff  milliseconds to wait before attempts 2 and 3
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly ?string $storefrontHost = null,
        private readonly int $timeout = 10,
        private readonly bool $insecureTls = false,
        private readonly array $backoff = [250, 750],
    ) {}

    public static function fromConfig(): self
    {
        $base = (string) config('erp.base_url');
        $storeId = (string) config('erp.store_id');
        $token = (string) config('erp.store_token');
        $local = app()->environment('local');

        if ($base === '' || $storeId === '' || $token === '') {
            throw new RuntimeException('ERP_BASE_URL, STORE_ID and STORE_TOKEN must be set.');
        }

        // The ERP refuses plain HTTP rather than redirecting it, because a
        // redirect would leak a signed request (§3.3). Only a local ERP behind
        // a development proxy is allowed to be spoken to without TLS.
        if (! str_starts_with($base, 'https://') && ! ($local && str_starts_with($base, 'http://'))) {
            throw new RuntimeException('ERP_BASE_URL must be https:// (plain http is allowed only with APP_ENV=local).');
        }

        return new self(
            $base,
            $storeId,
            $token,
            config('erp.storefront_host'),
            (int) config('erp.timeout'),
            $local && (bool) config('erp.insecure_tls'),
            array_values((array) config('erp.backoff_ms')),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null, null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, [], $body, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body, string $idempotencyKey): array
    {
        return $this->send('PATCH', $path, [], $body, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body, ?string $idempotencyKey): array
    {
        $rawQuery = $query === [] ? '' : http_build_query($this->clean($query), '', '&', PHP_QUERY_RFC3986);
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // A write with no key could not be retried safely, so it is given one.
        if ($method !== 'GET' && $idempotencyKey === null) {
            $idempotencyKey = Signature::nonce();
        }

        $attempts = count($this->backoff) + 1;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->once($method, $path, $rawQuery, $json, $idempotencyKey);
            } catch (ErpException $exception) {
                $last = $exception;

                if (! $exception->isUnavailable() || $attempt === $attempts) {
                    throw $exception;
                }

                $wait = $exception->retryAfter !== null
                    ? min($exception->retryAfter * 1000, 5000)
                    : $this->backoff[$attempt - 1] + random_int(0, 150);

                if ($wait > 0) {
                    Sleep::usleep($wait * 1000);
                }
            }
        }

        throw $last ?? ErpException::unreachable('no attempt was made');
    }

    /**
     * @return array<string, mixed>
     */
    private function once(string $method, string $path, string $rawQuery, ?string $json, ?string $idempotencyKey): array
    {
        $fullPath = (string) parse_url($this->baseUrl, PHP_URL_PATH).self::PREFIX.$path;
        $timestamp = (string) time();
        $nonce = Signature::nonce();

        $canonical = Signature::canonical($method, $fullPath, $rawQuery, $timestamp, $nonce, $json ?? '');
        $signature = Signature::sign($this->secret, $canonical);

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => Signature::authorization($this->keyId, $signature),
            'X-Feriwala-Timestamp' => $timestamp,
            'X-Feriwala-Nonce' => $nonce,
            'User-Agent' => 'Feriwala-Storefront/1.0',
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        if ($this->storefrontHost !== null) {
            $headers['X-Feriwala-Storefront-Host'] = $this->storefrontHost;
        }

        // The query is appended as signed, byte for byte, never re-encoded.
        $url = $this->baseUrl.self::PREFIX.$path.($rawQuery === '' ? '' : '?'.$rawQuery);

        // A redirect would carry a signed request somewhere it was not meant.
        $request = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout(5)
            ->withoutRedirecting();

        if ($this->insecureTls) {
            $request = $request->withoutVerifying();
        }

        if ($json !== null) {
            $request = $request->withBody($json, 'application/json');
        }

        try {
            $response = $request->send($method, $url);
        } catch (ConnectionException $exception) {
            throw ErpException::unreachable($exception->getMessage() !== '' ? $exception->getMessage() : 'connection failed');
        }

        return $this->interpret($response->status(), $response->header('Retry-After'), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    private function interpret(int $status, string $retryAfterHeader, string $rawBody): array
    {
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);
        $body = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            // An unreadable success is a fault, not an answer: nothing is known.
            if ($rawBody !== '' && ! is_array($decoded)) {
                throw new ErpException(502, 'malformed_response', 'The ERP answered with something unreadable.');
            }

            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $retryAfter = ctype_digit($retryAfterHeader) ? (int) $retryAfterHeader : null;

        throw new ErpException(
            $status,
            is_string($error['code'] ?? null) ? $error['code'] : 'http_'.$status,
            is_string($error['message'] ?? null) ? $error['message'] : 'The request was refused.',
            is_array($error['details'] ?? null) ? $error['details'] : [],
            is_string($error['request_id'] ?? null) ? $error['request_id'] : null,
            $retryAfter,
        );
    }

    /**
     * Drop nulls and render booleans as the API expects them.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function clean(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $out[$key] = $this->clean($value);
            } else {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
