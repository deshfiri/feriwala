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
     * Ask Steadfast to take one parcel. **This creates a live, potentially
     * chargeable consignment.**
     *
     * `POST /create_order` answers `{"status":200,"consignment":{"consignment_id":…,
     * "invoice":…,"tracking_code":…,"status":…}}` (package README §1). Only that
     * shape is a success; a 200 without a consignment id is *not* assumed to be
     * either way, and is raised as unexpected so the caller treats the outcome as
     * unknown rather than retrying.
     *
     * @throws SteadfastException
     */
    public function createOrder(SteadfastOrderRequest $order): SteadfastConsignment
    {
        $response = $this->send('POST', '/create_order', $order->toJson());
        $body = $response->json();
        $consignment = is_array($body) ? ($body['consignment'] ?? null) : null;

        if (! is_array($consignment)
            || (int) ($body['status'] ?? 0) !== 200
            || ! $this->isIdentifier($consignment['consignment_id'] ?? null)
            || ($consignment['invoice'] ?? null) !== $order->invoice) {
            throw new SteadfastException(SteadfastFailureKind::Unexpected, $response->status());
        }

        return new SteadfastConsignment(
            consignmentId: (string) $consignment['consignment_id'],
            trackingCode: is_string($consignment['tracking_code'] ?? null) && $consignment['tracking_code'] !== ''
                ? $consignment['tracking_code']
                : null,
            status: is_string($consignment['status'] ?? null) ? $consignment['status'] : null,
        );
    }

    /**
     * What Steadfast says about a parcel, by Banij's own invoice reference.
     *
     * `GET /status_by_invoice/{invoice}` answers `{"status":200,"delivery_status":…}`
     * (package README §3). The answer carries no consignment id.
     *
     * A 404 is read as "no such invoice". The package documents no error shape,
     * so that is an assumption to confirm against the real service; anything
     * that is neither a clear answer nor a 404 stays inconclusive.
     *
     * @throws SteadfastException
     */
    public function statusByInvoice(string $invoice): SteadfastStatusLookup
    {
        try {
            $response = $this->send('GET', '/status_by_invoice/'.rawurlencode($invoice));
        } catch (SteadfastException $exception) {
            if ($exception->httpStatus === 404) {
                return new SteadfastStatusLookup(found: false, status: null);
            }

            throw $exception;
        }

        $body = $response->json();

        if (! is_array($body) || (int) ($body['status'] ?? 0) !== 200 || ! is_string($body['delivery_status'] ?? null)) {
            throw new SteadfastException(SteadfastFailureKind::Unexpected, $response->status());
        }

        return new SteadfastStatusLookup(found: true, status: $body['delivery_status']);
    }

    protected function isIdentifier(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1);
    }

    /**
     * One call, with the failure kinds told apart.
     *
     * @param  string|null  $body  a JSON request body, or null for a plain GET
     *
     * @throws SteadfastException
     */
    protected function send(string $method, string $path, ?string $body = null): Response
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
            $response = ($body === null ? $request : $request->withBody($body, 'application/json'))
                ->send($method, $baseUrl.$path);
        } catch (ConnectionException $exception) {
            $this->logCall($method, $path, null);

            throw new SteadfastException($this->classify($exception));
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
     * Only a failure that provably happened before any request was sent is
     * "unreachable": cURL error 6 (could not resolve host) and 7 (could not
     * connect). A timeout may have reached Steadfast. Anything else -- a reset,
     * an empty reply, a TLS failure -- may have too, so it is not claimed to be
     * safe: it is "unexpected", whose outcome is unknown.
     */
    protected function classify(ConnectionException $exception): SteadfastFailureKind
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'cURL error 28'), stripos($message, 'timed out') !== false => SteadfastFailureKind::Timeout,
            str_contains($message, 'cURL error 6:'), str_contains($message, 'cURL error 7:') => SteadfastFailureKind::Unreachable,
            default => SteadfastFailureKind::Unexpected,
        };
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
