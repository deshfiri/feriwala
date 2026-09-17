<?php

namespace App\Domain\Website\Actions;

use App\Domain\Billing\PaymentLogRedactor;
use App\Domain\Website\Api\WebhookSignature;
use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebsiteConnectionHealth;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\SyncQueueFailure;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\WebhookLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One attempt at one delivery, and everything that follows from it
 * (contract §7.3, §7.4, §17.3, P5-20, P5-22, P5-26–P5-28).
 *
 * Sends the event signed with the endpoint's current secret, with the headers
 * the contract lists and a ten-second timeout, then writes down what happened:
 *
 *   - **delivered** on any 2xx — the product it was about is marked synchronised
 *     and the website's last synchronisation moves;
 *   - **retrying** otherwise, with the next attempt due after the contract's
 *     backoff, picked up by the scheduler;
 *   - **failed** once the retries are spent — the dead-letter state — with a
 *     row in the failed-sync queue for a person and the product marked failed.
 *
 * Every attempt is logged with its headers redacted, and the website's
 * connection health is worked out again from how its recent deliveries went.
 *
 * Re-reads the delivery under a row lock and does nothing to one that is
 * already finished, so a duplicate job — a retry dispatched twice — sends
 * nothing twice.
 */
class AttemptWebhookDelivery
{
    public function __construct(
        protected HttpFactory $http,
        protected PaymentLogRedactor $redactor,
    ) {}

    public function handle(int $deliveryId): void
    {
        $delivery = DB::transaction(function () use ($deliveryId) {
            /** @var WebhookDelivery|null $locked */
            $locked = WebhookDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($locked === null || $locked->state->isFinished()) {
                return null;
            }

            if ($locked->state === WebhookDeliveryState::Retrying
                && $locked->next_retry_at !== null
                && $locked->next_retry_at->isFuture()) {
                return null;
            }

            $locked->forceFill([
                'attempt' => $locked->attempt + 1,
                'dispatched_at' => CarbonImmutable::now(),
            ])->save();

            return $locked;
        });

        if (! $delivery instanceof WebhookDelivery) {
            return;
        }

        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()
            ->where('website_id', $delivery->website_id)
            ->where('is_active', true)
            ->first();

        $body = (string) json_encode($delivery->payload);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $started = hrtime(true);
        $status = null;
        $excerpt = null;
        $error = null;

        $headers = [
            'Content-Type' => 'application/json',
            WebhookSignature::HEADER_EVENT => $delivery->event_type->value,
            WebhookSignature::HEADER_DELIVERY => $delivery->event_id,
            WebhookSignature::HEADER_TIMESTAMP => $timestamp,
        ];

        if ($endpoint === null) {
            $error = 'No active webhook endpoint.';
        } else {
            try {
                $response = $this->http
                    ->timeout((int) config('website.webhooks.timeout_seconds', 10))
                    ->withHeaders($headers + [
                        WebhookSignature::HEADER_SIGNATURE => WebhookSignature::sign($endpoint->secret, $timestamp, $body),
                    ])
                    ->withBody($body, 'application/json')
                    ->withOptions(['allow_redirects' => false])
                    ->post($endpoint->url);

                $status = $response->status();
                $excerpt = mb_substr($response->body(), 0, 500);
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 500);
            }
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        WebhookLog::create([
            'webhook_delivery_id' => $delivery->id,
            'website_id' => $delivery->website_id,
            'attempt' => $delivery->attempt,
            'url' => $endpoint === null ? '' : $endpoint->url,
            'response_status' => $status,
            'duration_ms' => $durationMs,

            // The signature header was never added to these, and the redactor
            // would remove it if it had been (§42).
            'request_headers' => $this->redactor->redact($headers),
            'response_excerpt' => $excerpt,
            'error' => $error,
            'created_at' => CarbonImmutable::now(),
        ]);

        $succeeded = $status !== null && $status >= 200 && $status < 300;

        $succeeded
            ? $this->delivered($delivery, $status)
            : $this->notDelivered($delivery, $status, $error ?? 'The storefront answered '.$status.'.');

        $this->recomputeHealth($delivery->website_id);
    }

    protected function delivered(WebhookDelivery $delivery, int $status): void
    {
        $now = CarbonImmutable::now();

        $delivery->forceFill([
            'state' => WebhookDeliveryState::Delivered,
            'response_status' => $status,
            'responded_at' => $now,
            'delivered_at' => $now,
            'next_retry_at' => null,
            'last_error' => null,
        ])->save();

        Website::query()->whereKey($delivery->website_id)->update([
            'webhook_connected_at' => DB::raw('COALESCE(webhook_connected_at, NOW())'),
            'last_synced_at' => $now,
        ]);

        if ($delivery->event_type->concernsProduct() && $delivery->subject_type === 'website_product') {
            WebsiteProduct::query()
                ->whereKey($delivery->subject_id)
                // Only a copy that has not changed again since this was queued,
                // and without touching `updated_at`, which is the content's.
                ->where('updated_at', '<=', $delivery->updated_at)
                ->toBase()
                ->update([
                    'sync_status' => WebsiteSyncStatus::Synced->value,
                    'last_synced_at' => $now,
                    'sync_error' => null,
                ]);
        }

        SyncQueueFailure::query()
            ->where('webhook_delivery_id', $delivery->id)
            ->unresolved()
            ->update(['resolved_at' => $now]);
    }

    protected function notDelivered(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $now = CarbonImmutable::now();

        /** @var array<int, int> $backoff */
        $backoff = config('website.webhooks.backoff', [10, 30, 120, 600, 1800, 7200, 21600, 43200]);

        // attempt 1 failed → wait backoff[0] before attempt 2, and so on; once
        // every delay has been used the delivery is failed (contract §7.3).
        $delay = $backoff[$delivery->attempt - 1] ?? null;

        if ($delay !== null) {
            $delivery->forceFill([
                'state' => WebhookDeliveryState::Retrying,
                'response_status' => $status,
                'responded_at' => $status === null ? null : $now,
                'last_error' => $error,
                'next_retry_at' => $now->addSeconds($delay),
            ])->save();

            return;
        }

        $delivery->forceFill([
            'state' => WebhookDeliveryState::Failed,
            'response_status' => $status,
            'responded_at' => $status === null ? null : $now,
            'last_error' => $error,
            'next_retry_at' => null,
        ])->save();

        /** @var SyncQueueFailure|null $existing */
        $existing = SyncQueueFailure::query()
            ->where('webhook_delivery_id', $delivery->id)
            ->unresolved()
            ->first();

        $existing === null
            ? SyncQueueFailure::create([
                'website_id' => $delivery->website_id,
                'kind' => SyncQueueFailure::KIND_WEBHOOK,
                'webhook_delivery_id' => $delivery->id,
                'error' => $error,
                'attempts' => $delivery->attempt,
                'failed_at' => $now,
            ])
            : $existing->forceFill(['error' => $error, 'attempts' => $delivery->attempt, 'failed_at' => $now])->save();

        if ($delivery->event_type->concernsProduct() && $delivery->subject_type === 'website_product') {
            WebsiteProduct::query()->whereKey($delivery->subject_id)->toBase()->update([
                'sync_status' => WebsiteSyncStatus::Failed->value,
                'sync_error' => mb_substr($error, 0, 500),
            ]);
        }
    }

    /**
     * How the integration is behaving, from what actually happened (P5-28).
     *
     * Failing when anything was dead-lettered in the last day and is still
     * unresolved; degraded while deliveries are being retried; healthy when the
     * recent ones arrived. A website that has never been delivered to stays as
     * it was.
     */
    protected function recomputeHealth(int $websiteId): void
    {
        $failing = SyncQueueFailure::query()
            ->where('website_id', $websiteId)
            ->unresolved()
            ->where('failed_at', '>=', CarbonImmutable::now()->subDay())
            ->exists();

        $recent = WebhookDelivery::query()
            ->where('website_id', $websiteId)
            ->latest('id')
            ->limit(20)
            ->pluck('state');

        $health = match (true) {
            $failing => WebsiteConnectionHealth::Failing,
            $recent->contains(WebhookDeliveryState::Retrying) => WebsiteConnectionHealth::Degraded,
            $recent->contains(WebhookDeliveryState::Delivered) => WebsiteConnectionHealth::Healthy,
            default => null,
        };

        if ($health !== null) {
            Website::query()->whereKey($websiteId)->update(['connection_health' => $health->value]);
        }
    }
}
