<?php

namespace App\Integrations\Sms\Providers\GenNet;

use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use App\Integrations\Sms\Exceptions\SmsProviderUnavailable;
use App\Integrations\Sms\SmsCredentials;
use App\Integrations\Sms\SmsGateway;
use App\Support\Localization\MobileNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;

/**
 * GenNet Push SMS (API documentation v3.0.0).
 *
 * An additional provider behind the same contract as every other: nothing that
 * queues, records or switches SMS knows it exists. It is used when an
 * administrator selects it and gives it credentials.
 *
 * **A message is never sent twice because a reply was lost.** When the answer
 * to a send cannot be read — a timeout, a dropped connection, a body that is not
 * JSON — GenNet may or may not have the message. That is recorded as a refusal
 * with the reason, not as something to retry, because retrying could text a
 * customer twice (and a second one-time code invalidates the first). Only
 * GenNet's own request-limit and TPS refusals, which are made before anything
 * is sent, are retryable.
 *
 * The API token never reaches a log, an audit entry, a toast or a prop, and
 * neither does a message body: logs carry the path, the HTTP status and
 * GenNet's own status code.
 */
class GenNetSmsProvider extends SmsGateway
{
    /** GenNet's per-request ceilings for bulk and dynamic sends. */
    public const BATCH_LIMIT = 100;

    /** GenNet's maximum SMS body length, in characters. */
    public const MAX_BODY_LENGTH = 1000;

    public function __construct(
        protected GenNetCredentials $credentials,
        protected Factory $http,
        protected LogManager $log,
        protected MobileNumber $numbers,
    ) {}

    protected function credentials(): SmsCredentials
    {
        return $this->credentials;
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function send(SmsMessage $message): SmsResult
    {
        $this->requireConfigured();

        $refusal = $this->localRefusal($message->to, $message->body);

        if ($refusal !== null) {
            return $refusal['result'];
        }

        try {
            $body = $this->call('POST', '/api/v3/send-sms', [
                'sid' => $this->credentials->require('sid'),
                'msisdn' => $this->msisdn($message->to),
                'sms' => $message->body,
                'csms_id' => $this->csmsId(),
            ]);
        } catch (SmsProviderUnavailable $exception) {
            return $this->unknownOutcome($exception);
        }

        if (! $this->succeeded($body)) {
            return $this->refusal($body);
        }

        return $this->rowResult($this->rows($body)[0] ?? null);
    }

    /**
     * One body to many numbers, in requests of at most {@see BATCH_LIMIT}.
     *
     * @param  array<int, string>  $numbers
     * @return array<int, SmsResult> keyed like `$numbers`
     */
    public function sendBulk(array $numbers, string $body): array
    {
        $this->requireConfigured();

        $results = [];
        $pending = [];

        foreach ($numbers as $index => $number) {
            $refusal = $this->localRefusal($number, $body);

            if ($refusal !== null) {
                $results[$index] = $refusal['result'];

                continue;
            }

            $pending[$index] = $this->msisdn($number);
        }

        foreach (array_chunk($pending, self::BATCH_LIMIT, preserve_keys: true) as $chunk) {
            $batchId = $this->csmsId();

            try {
                $response = $this->call('POST', '/api/v3/send-sms/bulk', [
                    'sid' => $this->credentials->require('sid'),
                    'msisdn' => array_values($chunk),
                    'sms' => $body,
                    'batch_csms_id' => $batchId,
                ]);
            } catch (SmsProviderUnavailable $exception) {
                foreach ($chunk as $index => $ignored) {
                    $results[$index] = $this->unknownOutcome($exception);
                }

                continue;
            }

            $results += $this->resultsByNumber($response, $chunk);
        }

        ksort($results);

        return $results;
    }

    /**
     * A different body for each number, in requests of at most {@see BATCH_LIMIT}.
     *
     * @param  array<int, array{to: string, body: string}>  $messages
     * @return array<int, SmsResult> keyed like `$messages`
     */
    public function sendDynamic(array $messages): array
    {
        $this->requireConfigured();

        $results = [];
        $pending = [];

        foreach ($messages as $index => $message) {
            $refusal = $this->localRefusal($message['to'], $message['body']);

            if ($refusal !== null) {
                $results[$index] = $refusal['result'];

                continue;
            }

            $pending[$index] = [
                'text' => $message['body'],
                'msisdn' => $this->msisdn($message['to']),
                'csms_id' => $this->csmsId(),
            ];
        }

        foreach (array_chunk($pending, self::BATCH_LIMIT, preserve_keys: true) as $chunk) {
            try {
                $response = $this->call('POST', '/api/v3/send-sms/dynamic', [
                    'sid' => $this->credentials->require('sid'),
                    'sms' => array_values($chunk),
                ]);
            } catch (SmsProviderUnavailable $exception) {
                foreach ($chunk as $index => $ignored) {
                    $results[$index] = $this->unknownOutcome($exception);
                }

                continue;
            }

            $results += $this->resultsByNumber(
                $response,
                array_map(fn (array $row) => $row['msisdn'], $chunk),
            );
        }

        ksort($results);

        return $results;
    }

    /*
    |--------------------------------------------------------------------------
    | Balance, delivery status, incoming messages
    |--------------------------------------------------------------------------
    */

    /**
     * The sender ID's balance, or null when it cannot be read. Never throws:
     * the contract treats an unreadable balance as an absent one. The admin
     * screen uses {@see fetchBalance()} when it wants to say why.
     */
    public function balance(): ?string
    {
        try {
            return $this->fetchBalance();
        } catch (SmsProviderUnavailable) {
            return null;
        }
    }

    /**
     * @throws SmsProviderUnavailable with GenNet's reason when it cannot be read
     */
    public function fetchBalance(): string
    {
        $this->requireConfigured();

        $body = $this->call('GET', '/api/v3/balance', []);

        if (! $this->succeeded($body)) {
            throw new SmsProviderUnavailable($this->refusal($body)->error ?? 'GenNet refused the request.');
        }

        /** @var mixed $info */
        $info = $body['smsinfo']['balance_info'] ?? null;
        $sid = $this->credentials->require('sid');

        if (! is_array($info) || $info === []) {
            throw SmsProviderUnavailable::malformedResponse($this->name());
        }

        // The configured sender ID's own balance; otherwise the first one
        // GenNet lists, which is the only one for a single-sender account.
        foreach ($info as $row) {
            if (is_array($row) && ($row['sender_id'] ?? null) === $sid && isset($row['sms_balance'])) {
                return (string) $row['sms_balance'];
            }
        }

        $first = $info[0] ?? null;

        if (! is_array($first) || ! isset($first['sms_balance'])) {
            throw SmsProviderUnavailable::malformedResponse($this->name());
        }

        return (string) $first['sms_balance'];
    }

    /**
     * What GenNet says became of a message, by the reference it gave back.
     *
     * @return array{status: string, message: string|null}
     *
     * @throws SmsProviderUnavailable
     */
    public function deliveryStatus(string $reference): array
    {
        $this->requireConfigured();

        $body = $this->call('GET', '/api/v3/send-sms/status', ['reference_id' => $reference]);

        if (! $this->succeeded($body)) {
            throw new SmsProviderUnavailable($this->refusal($body)->error ?? 'GenNet refused the request.');
        }

        $row = $this->rows($body)[0] ?? null;

        if ($row === null || ! is_string($row['sms_status'] ?? null)) {
            throw SmsProviderUnavailable::malformedResponse($this->name());
        }

        return [
            'status' => $row['sms_status'],
            'message' => is_string($row['status_message'] ?? null) ? $row['status_message'] : null,
        ];
    }

    /**
     * Messages received on the account's non-masking sender IDs.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SmsProviderUnavailable
     */
    public function incomingMessages(): array
    {
        $this->requireConfigured();

        $body = $this->call('GET', '/api/v3/incoming-messages-list', []);

        // This endpoint answers `error` rather than `status` (documentation §4.7).
        if (($body['error'] ?? true) !== false || ! is_array($body['messages'] ?? null)) {
            throw SmsProviderUnavailable::forProvider($this->name(), 'it did not return the message list');
        }

        return array_values(array_filter($body['messages'], 'is_array'));
    }

    /*
    |--------------------------------------------------------------------------
    | The request
    |--------------------------------------------------------------------------
    */

    /**
     * One call, decoded. JSON in the body for every method, as the
     * documentation shows (§4), so the token never sits in a URL.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SmsProviderUnavailable when GenNet cannot be reached or answers
     *                                something that is not JSON
     */
    protected function call(string $method, string $path, array $payload): array
    {
        $url = $this->credentials->requireBaseUrl().$path;

        try {
            $response = $this->http
                ->withoutRedirecting()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) config('sms.providers.gennet.timeout', 20))
                ->withBody(
                    json_encode(['api_token' => $this->credentials->require('api_token')] + $payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'application/json',
                )
                ->send($method, $url);
        } catch (ConnectionException) {
            $this->logCall($method, $path, null, null);

            throw SmsProviderUnavailable::forProvider($this->name(), 'the connection failed or timed out');
        }

        $decoded = $response->json();

        $this->logCall($method, $path, $response, is_array($decoded) ? $decoded : null);

        if (! is_array($decoded)) {
            throw SmsProviderUnavailable::malformedResponse($this->name());
        }

        return $decoded;
    }

    /**
     * Path, HTTP status and GenNet's own status code — nothing that identifies
     * a recipient or carries a body or a credential.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function logCall(string $method, string $path, ?Response $response, ?array $body): void
    {
        $this->log->channel('sms')->info('GenNet call', [
            'host' => parse_url((string) $this->credentials->baseUrl(), PHP_URL_HOST),
            'method' => $method,
            'path' => $path,
            'http_status' => $response?->status(),
            'status_code' => $body['status_code'] ?? null,
            'error_message' => is_string($body['error_message'] ?? null) && ($body['status'] ?? null) !== 'SUCCESS'
                ? $body['error_message']
                : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the answer
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $body
     */
    protected function succeeded(array $body): bool
    {
        return strtoupper((string) ($body['status'] ?? '')) === 'SUCCESS'
            && (int) ($body['status_code'] ?? 0) === 200;
    }

    /**
     * The whole request was refused. Rejected for good except where GenNet
     * says it refused before sending.
     *
     * @param  array<string, mixed>  $body
     */
    protected function refusal(array $body): SmsResult
    {
        $code = (int) ($body['status_code'] ?? 5000);
        $message = GenNetStatusCode::describe(
            $code,
            is_string($body['error_message'] ?? null) ? $body['error_message'] : null,
        );

        return GenNetStatusCode::isRetryable($code)
            ? SmsResult::failed($message, (string) $code)
            : SmsResult::rejected($message, (string) $code);
    }

    /**
     * What the gateway did with one message of a request that was accepted.
     *
     * @param  array<string, mixed>|null  $row
     */
    protected function rowResult(?array $row): SmsResult
    {
        if ($row === null) {
            // A success with no message in it cannot be told apart from one
            // that was not taken, so it is not reported as sent.
            return SmsResult::rejected('GenNet answered without a message status.', 'malformed');
        }

        $status = strtoupper((string) ($row['sms_status'] ?? ''));

        if ($status === 'SUCCESS') {
            $reference = $row['reference_id'] ?? null;

            return SmsResult::accepted(is_string($reference) || is_int($reference) ? (string) $reference : null);
        }

        return SmsResult::rejected(
            is_string($row['status_message'] ?? null) && $row['status_message'] !== ''
                ? $row['status_message']
                : 'GenNet did not accept the message.',
            $status !== '' ? $status : null,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    protected function rows(array $body): array
    {
        $rows = $body['smsinfo'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Match a multi-message answer back to what was asked for, by number.
     *
     * A number GenNet does not mention is not reported as sent.
     *
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $sent  input index => number as sent
     * @return array<int, SmsResult>
     */
    protected function resultsByNumber(array $response, array $sent): array
    {
        if (! $this->succeeded($response)) {
            $refusal = $this->refusal($response);

            return array_map(fn () => $refusal, $sent);
        }

        $byNumber = [];

        foreach ($this->rows($response) as $row) {
            if (is_string($row['msisdn'] ?? null)) {
                $byNumber[ltrim($row['msisdn'], '+')] = $row;
            }
        }

        $results = [];

        foreach ($sent as $index => $number) {
            $results[$index] = $this->rowResult($byNumber[$number] ?? null);
        }

        return $results;
    }

    /**
     * The reply to a send was lost, so whether the message left is not known.
     */
    protected function unknownOutcome(SmsProviderUnavailable $exception): SmsResult
    {
        return SmsResult::rejected(
            $exception->getMessage().' Whether GenNet received the message is not known, so it was not retried.',
            'unknown',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Numbers, bodies and references
    |--------------------------------------------------------------------------
    */

    /**
     * Refuse here what GenNet would refuse anyway, without spending a call.
     *
     * @return array{result: SmsResult}|null
     */
    protected function localRefusal(string $number, string $body): ?array
    {
        if ($this->numbers->normalise($number) === null) {
            return ['result' => SmsResult::rejected(GenNetStatusCode::describe(4025), '4025')];
        }

        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return ['result' => SmsResult::rejected(GenNetStatusCode::describe(4027), '4027')];
        }

        return null;
    }

    /**
     * GenNet's number form: the E.164 digits without the plus (`8801712345678`),
     * from the platform's own normalisation.
     */
    protected function msisdn(string $number): string
    {
        return ltrim((string) $this->numbers->normalise($number), '+');
    }

    /**
     * A reference of 20 capital letters and digits, new for every message.
     * GenNet refuses a repeat within the day, and 36^20 does not repeat.
     */
    protected function csmsId(): string
    {
        return strtoupper(Str::random(20));
    }
}
