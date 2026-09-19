<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Website\Actions\AttemptWebhookDelivery;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Actions\ManageWebsiteCredentials;
use App\Domain\Website\Actions\RetryWebhookDelivery;
use App\Domain\Website\Actions\SyncWebsiteCatalogue;
use App\Domain\Website\Actions\SyncWebsiteInventory;
use App\Domain\Website\Data\IssuedCredential;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\ApiLog;
use App\Domain\Website\Models\SyncQueueFailure;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connecting a storefront to the ERP (§17.3, contract §3, §9, P5-17, P5-27, P5-28).
 *
 * The partner's own website's credentials — issued, rotated, revoked — and the
 * record of the calls its storefront has made. Self-scoped like every other
 * website screen.
 *
 * **A secret is shown exactly once**, in the response to issuing or rotating it,
 * as flash data that is gone by the next page load. It is never a page prop a
 * reload could bring back, and never in a log.
 */
class WebsiteIntegrationController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
    ) {}

    public function show(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return Inertia::render('websites/integration', [
            'website' => $this->overview->detail($record),
            'credentials' => WebsiteCredential::query()
                ->where('website_id', $record->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (WebsiteCredential $credential) => [
                    'id' => $credential->public_id,
                    'name' => $credential->name,
                    'key_id' => $credential->key_id,
                    'secret_hint' => $credential->secret_hint,
                    'scopes' => $credential->scopes,
                    'last_used_at' => $credential->last_used_at?->toIso8601String(),
                    'rotated_at' => $credential->rotated_at?->toIso8601String(),
                    'previous_secret_expires_at' => $credential->previous_secret_expires_at?->toIso8601String(),
                    'revoked_at' => $credential->revoked_at?->toIso8601String(),
                    'revoked_reason' => $credential->revoked_reason,
                    'created_at' => $credential->created_at->toIso8601String(),
                ])
                ->all(),
            'scopes' => array_map(fn (CredentialScope $scope) => [
                'value' => $scope->value,
                'label' => __('website.integration.scopes.'.$scope->value),
                'default' => in_array($scope, CredentialScope::defaults(), true),
            ], CredentialScope::cases()),

            // The last calls this storefront made, as they were answered. The
            // log was redacted before it was stored; nothing here is a secret.
            'recent_calls' => ApiLog::query()
                ->where('website_id', $record->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ApiLog $log) => [
                    'request_id' => $log->request_id,
                    'method' => $log->method,
                    'path' => $log->path,
                    'status' => $log->status,
                    'error_code' => $log->error_code,
                    'duration_ms' => $log->duration_ms,
                    'created_at' => $log->created_at->toIso8601String(),
                ])
                ->all(),
            'api_base' => url('/api/storefront/v1'),

            // Where the storefront is told things (contract §7.2), never with
            // its secret — only the last four characters, to say which it is.
            'webhook' => $this->webhookFor($record),

            // The delivery record (contract §7.4), newest first.
            'deliveries' => WebhookDelivery::query()
                ->where('website_id', $record->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (WebhookDelivery $delivery) => [
                    'event_id' => $delivery->event_id,
                    'event_type' => $delivery->event_type->value,
                    'state' => $delivery->state->value,
                    'attempt' => $delivery->attempt,
                    'response_status' => $delivery->response_status,
                    'last_error' => AttemptWebhookDelivery::describe($delivery->response_status, $delivery->last_error),
                    'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
                    'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                    'created_at' => $delivery->created_at->toIso8601String(),
                ])
                ->all(),

            // The dead-letter queue (§17.3, P5-26): what a person has to retry.
            'failures' => SyncQueueFailure::query()
                ->where('website_id', $record->id)
                ->unresolved()
                ->with('delivery')
                ->latest('id')
                ->get()
                ->map(fn (SyncQueueFailure $failure) => [
                    'id' => $failure->public_id,
                    'event_id' => $failure->delivery?->event_id,
                    'event_type' => $failure->delivery?->event_type->value,
                    'error' => (string) AttemptWebhookDelivery::describe($failure->delivery?->response_status, $failure->error),
                    'attempts' => $failure->attempts,
                    'retry_count' => $failure->retry_count,
                    'failed_at' => $failure->failed_at->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Where the storefront is told things (contract §7.2, P5-20).
     */
    public function storeWebhook(Request $request, string $website, ManageWebhookEndpoint $webhooks): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:https'],
        ]);

        $result = $this->attempt(fn () => $webhooks->configure($record, $this->person($request), $validated['url']));

        if ($result['secret'] !== null) {
            Inertia::flash('webhook_secret', $result['secret']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.webhook_saved')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    public function rotateWebhook(Request $request, string $website, ManageWebhookEndpoint $webhooks): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $secret = $webhooks->rotate($this->endpointFor($record), $this->person($request));

        Inertia::flash('webhook_secret', $secret);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.webhook_rotated')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    public function disableWebhook(Request $request, string $website, ManageWebhookEndpoint $webhooks): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $webhooks->disable($this->endpointFor($record), $this->person($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.webhook_disabled')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    /**
     * Send a failed delivery again, with the same event identifier (P5-25, P5-26).
     */
    public function retryDelivery(
        Request $request,
        string $website,
        string $delivery,
        RetryWebhookDelivery $retry,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        /** @var WebhookDelivery|null $found */
        $found = WebhookDelivery::query()
            ->where('website_id', $record->id)
            ->where('event_id', $delivery)
            ->first();

        abort_if($found === null, 404);

        $this->attempt(fn () => $retry->handle($found, $this->person($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.delivery_retried')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    /**
     * Tell the storefront about everything now (§17.2's manual mode, P5-25).
     */
    public function syncNow(
        Request $request,
        string $website,
        SyncWebsiteCatalogue $catalogue,
        SyncWebsiteInventory $inventory,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        // Each press queues a delivery per published product; a second press
        // inside the minute would only queue them all again.
        $key = 'website-sync-now:'.$record->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages(['sync' => __('website.refused.sync_too_soon')]);
        }

        RateLimiter::hit($key, 60);

        $record->loadMissing('businessAccount');

        $catalogue->handle($record, everything: true);
        $inventory->forWebsite($record);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.sync_queued')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function webhookFor(Website $website): ?array
    {
        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()->where('website_id', $website->id)->first();

        return $endpoint === null ? null : [
            'url' => $endpoint->url,
            'secret_hint' => $endpoint->secret_hint,
            'is_active' => $endpoint->is_active,
            'rotated_at' => $endpoint->rotated_at?->toIso8601String(),
            'previous_secret_expires_at' => $endpoint->previous_secret_expires_at?->toIso8601String(),
        ];
    }

    protected function endpointFor(Website $website): WebsiteWebhookEndpoint
    {
        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()->with('website')->where('website_id', $website->id)->first();

        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    public function storeCredential(Request $request, string $website, ManageWebsiteCredentials $credentials): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::enum(CredentialScope::class)],
        ]);

        $issued = $this->attempt(fn () => $credentials->issue(
            $record,
            $this->person($request),
            $validated['name'],
            array_map(fn (string $scope) => CredentialScope::from($scope), $validated['scopes']),
        ));

        $this->showOnce($issued);

        return to_route('websites.integration.show', $record->public_id);
    }

    public function rotateCredential(
        Request $request,
        string $website,
        string $credential,
        ManageWebsiteCredentials $credentials,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $issued = $this->attempt(fn () => $credentials->rotate(
            $this->credentialFor($record, $credential),
            $this->person($request),
        ));

        $this->showOnce($issued);

        return to_route('websites.integration.show', $record->public_id);
    }

    public function revokeCredential(
        Request $request,
        string $website,
        string $credential,
        ManageWebsiteCredentials $credentials,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $credentials->revoke(
            $this->credentialFor($record, $credential),
            $this->person($request),
            $validated['reason'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.credential_revoked')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    /**
     * Hand the secret to the one response that may carry it.
     */
    protected function showOnce(IssuedCredential $issued): void
    {
        Inertia::flash('credential', [
            'key_id' => $issued->credential->key_id,
            'secret' => $issued->secret,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.credential_issued')]);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $step
     * @return TReturn
     */
    protected function attempt(callable $step): mixed
    {
        try {
            return $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }

    protected function credentialFor(Website $website, string $credential): WebsiteCredential
    {
        /** @var WebsiteCredential|null $record */
        $record = WebsiteCredential::query()
            ->with('website')
            ->where('website_id', $website->id)
            ->where('public_id', $credential)
            ->first();

        abort_if($record === null, 404);

        return $record;
    }

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $website);

        abort_if($record === null, 404);

        return $record;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
