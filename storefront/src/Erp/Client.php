<?php

declare(strict_types=1);

namespace Storefront\Erp;

use Storefront\Support\Env;

/**
 * Talks to the Feriwala storefront API (contract v1).
 *
 * Every request is signed. Reads and writes are retried on `429` and `5xx` with
 * exponential backoff and jitter — and only those; a `4xx` is an answer, not a
 * fault (§8.4). Each attempt is a fresh request with a fresh nonce and
 * timestamp, because a nonce is accepted once. Every write carries an
 * `Idempotency-Key`, which is what makes that retry safe (§4.7).
 *
 * The transport is injectable so the whole client can be exercised without a
 * network.
 */
final class Client
{
    private const PREFIX = '/api/storefront/v1';

    /** @var callable(string, string, array<int, string>, string|null, int): array{status: int, headers: array<string, string>, body: string} */
    private $transport;

    /** @var list<int> milliseconds to wait before attempts 2 and 3 */
    private array $backoff = [250, 750];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly ?string $storefrontHost = null,
        private readonly int $timeout = 10,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [$this, 'curl'];
    }

    public static function fromEnv(): self
    {
        $base = rtrim((string) Env::get('ERP_BASE_URL', ''), '/');

        if ($base === '' || Env::get('ERP_KEY_ID', '') === '' || Env::get('ERP_SECRET', '') === '') {
            throw new \RuntimeException('ERP_BASE_URL, ERP_KEY_ID and ERP_SECRET must be set.');
        }

        // The ERP refuses plain HTTP rather than redirecting it, because a
        // redirect would leak a signed request (§3.3). Only a local ERP behind
        // a development proxy is allowed to be spoken to without TLS.
        if (! str_starts_with($base, 'https://') && ! (Env::isLocal() && str_starts_with($base, 'http://'))) {
            throw new \RuntimeException('ERP_BASE_URL must be https:// (plain http is allowed only with APP_ENV=local).');
        }

        return new self(
            $base,
            (string) Env::get('ERP_KEY_ID'),
            (string) Env::get('ERP_SECRET'),
            Env::get('ERP_STOREFRONT_HOST') ?: null,
            Env::int('ERP_TIMEOUT', 10),
        );
    }

    public function withoutBackoff(): self
    {
        $clone = clone $this;
        $clone->backoff = [0, 0];

        return $clone;
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
                    usleep($wait * 1000);
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
            'Accept: application/json',
            'Authorization: '.Signature::authorization($this->keyId, $signature),
            'X-Feriwala-Timestamp: '.$timestamp,
            'X-Feriwala-Nonce: '.$nonce,
            'User-Agent: Feriwala-Storefront/1.0',
        ];

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }

        if ($this->storefrontHost !== null) {
            $headers[] = 'X-Feriwala-Storefront-Host: '.$this->storefrontHost;
        }

        $url = $this->baseUrl.self::PREFIX.$path.($rawQuery === '' ? '' : '?'.$rawQuery);
        $response = ($this->transport)($method, $url, $headers, $json, $this->timeout);

        return $this->interpret($response);
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string}  $response
     * @return array<string, mixed>
     */
    private function interpret(array $response): array
    {
        $status = $response['status'];
        $decoded = $response['body'] === '' ? [] : json_decode($response['body'], true);
        $body = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            // An unreadable success is a fault, not an answer: nothing is known.
            if ($response['body'] !== '' && ! is_array($decoded)) {
                throw new ErpException(502, 'malformed_response', 'The ERP answered with something unreadable.');
            }

            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $retryAfter = isset($response['headers']['retry-after']) && ctype_digit($response['headers']['retry-after'])
            ? (int) $response['headers']['retry-after']
            : null;

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

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $responseHeaders = [];
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // A redirect would carry a signed request somewhere it was not meant.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => ! (Env::isLocal() && Env::bool('ERP_INSECURE_TLS')),
            CURLOPT_SSL_VERIFYHOST => (Env::isLocal() && Env::bool('ERP_INSECURE_TLS')) ? 0 : 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);

        // The handle is released when it goes out of scope; curl_close() has had
        // no effect since PHP 8.0 and is deprecated in 8.5.
        if ($raw === false) {
            $reason = curl_error($handle);

            throw ErpException::unreachable($reason !== '' ? $reason : 'connection failed');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $raw];
    }
}
