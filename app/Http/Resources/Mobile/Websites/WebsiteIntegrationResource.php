<?php

namespace App\Http\Resources\Mobile\Websites;

use App\Domain\Website\Actions\AttemptWebhookDelivery;
use App\Domain\Website\Models\ApiLog;
use App\Domain\Website\Models\SyncQueueFailure;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * How the storefront is connected to the ERP, as its owner may read it (D32 §2).
 *
 * **No secret, ever.** A credential is shown by its key id and the last
 * characters of its secret (`secret_hint`) — just what the web screen shows. The
 * secret itself is issued, rotated and revoked by platform staff, who configure
 * the storefront by hand; this API cannot issue or reveal one, and the
 * credential models hide `secret` and `previous_secret` from serialisation as
 * well. The webhook is likewise its address and a hint, never the signing secret.
 *
 * @property array{credentials: Collection<int, WebsiteCredential>, recent_calls: Collection<int, ApiLog>, webhook: WebsiteWebhookEndpoint|null, deliveries: Collection<int, WebhookDelivery>, failures: Collection<int, SyncQueueFailure>} $resource
 */
class WebsiteIntegrationResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** The base address the storefront calls. */
            'api_base' => url('/api/storefront/v1'),
            'credentials' => $this->resource['credentials']->map(fn (WebsiteCredential $credential) => [
                /** Public identifier (ULID). */
                'id' => $credential->public_id,
                'name' => $credential->name,
                'key_id' => $credential->key_id,
                /** The last few characters of the secret, to say which one it is. Never the secret. */
                'secret_hint' => $credential->secret_hint,
                'scopes' => $credential->scopes,
                'last_used_at' => $credential->last_used_at?->toIso8601String(),
                'rotated_at' => $credential->rotated_at?->toIso8601String(),
                'previous_secret_expires_at' => $credential->previous_secret_expires_at?->toIso8601String(),
                'revoked_at' => $credential->revoked_at?->toIso8601String(),
                'revoked_reason' => $credential->revoked_reason,
                'created_at' => $credential->created_at->toIso8601String(),
            ])->values()->all(),
            /** The storefront's last twenty calls, as they were answered (already redacted). */
            'recent_calls' => $this->resource['recent_calls']->map(fn (ApiLog $log) => [
                'request_id' => $log->request_id,
                'method' => $log->method,
                'path' => $log->path,
                'status' => $log->status,
                'error_code' => $log->error_code,
                'duration_ms' => $log->duration_ms,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values()->all(),
            /** Where the storefront is told things; null until staff set one up. */
            'webhook' => $this->resource['webhook'] === null ? null : [
                'url' => $this->resource['webhook']->url,
                /** The last few characters of the signing secret. Never the secret. */
                'secret_hint' => $this->resource['webhook']->secret_hint,
                'is_active' => $this->resource['webhook']->is_active,
                'rotated_at' => $this->resource['webhook']->rotated_at?->toIso8601String(),
                'previous_secret_expires_at' => $this->resource['webhook']->previous_secret_expires_at?->toIso8601String(),
            ],
            /** The delivery record, newest first, twenty at most. */
            'deliveries' => $this->resource['deliveries']->map(fn (WebhookDelivery $delivery) => [
                /** Identifies the delivery in the retry path. */
                'event_id' => $delivery->event_id,
                'event_type' => $delivery->event_type->value,
                'state' => $delivery->state->value,
                'attempt' => $delivery->attempt,
                'response_status' => $delivery->response_status,
                'last_error' => AttemptWebhookDelivery::describe($delivery->response_status, $delivery->last_error),
                'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                'created_at' => $delivery->created_at->toIso8601String(),
            ])->values()->all(),
            /** Deliveries that gave up and are waiting for a person to retry them. */
            'failures' => $this->resource['failures']->map(fn (SyncQueueFailure $failure) => [
                /** Public identifier (ULID). */
                'id' => $failure->public_id,
                'event_id' => $failure->delivery?->event_id,
                'event_type' => $failure->delivery?->event_type->value,
                'error' => (string) AttemptWebhookDelivery::describe($failure->delivery?->response_status, $failure->error),
                'attempts' => $failure->attempts,
                'retry_count' => $failure->retry_count,
                'failed_at' => $failure->failed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
