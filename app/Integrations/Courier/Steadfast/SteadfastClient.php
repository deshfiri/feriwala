<?php

namespace App\Integrations\Courier\Steadfast;

use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Log\LogManager;
use InvalidArgumentException;

/**
 * Steadfast's HTTP API, limited to the calls its maintained package
 * (steadfast-it/SteadFast-Courier-Laravel-Package) demonstrates.
 *
 * Every call sends `Api-Key`, `Secret-Key` and `Content-Type: application/json`
 * (package `SteadfastCourier.php`). Redirects are never followed, so a
 * redirecting host cannot carry the keys elsewhere.
 *
 * The keys never reach a log, an exception message, an audit row or a
 * notification: logs carry the host, path, HTTP status and nothing else.
 */
class SteadfastClient
{
    public function __construct(
        protected SteadfastCredentials $credentials,
        protected Factory $http,
        protected LogManager $log,
    ) {}

    /**
     * The account balance, in flat Taka. A read: no booking, no consignment,
     * no charge.
     *
     * `GET /get_balance` answers `{"status":200,"current_balance":0}`. The
     * amount is read from the raw body rather than from decoded JSON so it
     * never passes through a float.
     *
     * @throws SteadfastException
     */
    public function balance(): Money
    {
        $response = $this->send('GET', '/get_balance');
        $body = $response->json();

        if (! is_array($body) || (int) ($body['status'] ?? 0) !== 200) {
            throw new SteadfastException(SteadfastFailureKind::Unexpected, $response->status());
        }

        $token = $this->rawNumber($response->body(), 'current_balance');

        if ($token === null) {
            throw new SteadfastException(SteadfastFailureKind::Unexpected, $response->status());
        }

        try {
            return DecimalAmount::parse($token, Currency::BDT, allowNegative: true);
        } catch (InvalidArgumentException) {
            throw new SteadfastException(SteadfastFailureKind::Unexpected, $response->status());
        }
    }

    /**
     * One call, with the failure kinds told apart.
     *
     * @param  array<string, mixed>|null  $payload
     *
     * @throws SteadfastException
     */
    protected function send(string $method, string $path, ?array $payload = null): Response
    {
        $baseUrl = $this->credentials->baseUrl();
        $apiKey = $this->credentials->apiKey();
        $secretKey = $this->credentials->secretKey();

        if ($baseUrl === null || $apiKey === null || $secretKey === null) {
            throw new SteadfastException(SteadfastFailureKind::NotConfigured);
        }

        $request = $this->http
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders([
                'Api-Key' => $apiKey,
                'Secret-Key' => $secretKey,
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout((int) config('courier.steadfast.connect_timeout', 5))
            ->timeout((int) config('courier.steadfast.timeout', 20));

        try {
            $response = $payload === null
                ? $request->send($method, $baseUrl.$path)
                : $request->send($method, $baseUrl.$path, ['json' => $payload]);
        } catch (ConnectionException $exception) {
            $this->logCall($method, $path, null);

            throw new SteadfastException($this->isTimeout($exception)
                ? SteadfastFailureKind::Timeout
                : SteadfastFailureKind::Unreachable);
        }

        $this->logCall($method, $path, $response->status());

        return match (true) {
            in_array($response->status(), [401, 403], true) => throw new SteadfastException(SteadfastFailureKind::Authentication, $response->status()),
            $response->status() === 422 => throw new SteadfastException(SteadfastFailureKind::Validation, 422),
            ! $response->successful() => throw new SteadfastException(SteadfastFailureKind::Unexpected, $response->status()),
            default => $response,
        };
    }

    /**
     * cURL error 28 is "operation timed out"; everything else that stops a
     * connection (DNS, refused, TLS) is "unreachable".
     */
    protected function isTimeout(ConnectionException $exception): bool
    {
        return str_contains($exception->getMessage(), 'cURL error 28')
            || stripos($exception->getMessage(), 'timed out') !== false;
    }

    /**
     * The literal number (or numeric string) a top-level key holds, without
     * decoding it to a float.
     */
    protected function rawNumber(string $json, string $key): ?string
    {
        if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*"?(-?\d+(?:\.\d+)?)"?/', $json, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function logCall(string $method, string $path, ?int $httpStatus): void
    {
        $this->log->info('Steadfast call', [
            'host' => parse_url((string) $this->credentials->baseUrl(), PHP_URL_HOST),
            'method' => $method,
            'path' => $path,
            'http_status' => $httpStatus,
        ]);
    }
}
