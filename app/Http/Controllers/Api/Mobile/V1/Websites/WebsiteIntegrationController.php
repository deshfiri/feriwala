<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites;

use App\Domain\Website\Actions\RetryWebhookDelivery;
use App\Domain\Website\Actions\SyncWebsiteCatalogue;
use App\Domain\Website\Actions\SyncWebsiteInventory;
use App\Domain\Website\Models\ApiLog;
use App\Domain\Website\Models\SyncQueueFailure;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Websites\Concerns\ResolvesOwnWebsite;
use App\Http\Resources\Mobile\Websites\WebsiteDetailResource;
use App\Http\Resources\Mobile\Websites\WebsiteIntegrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a storefront to the ERP (§17.3).
 *
 * **The owner sees credentials and the webhook but never manages them.** Issuing,
 * rotating and revoking a credential, and setting, rotating or switching off the
 * webhook and its signing secret, belong to authorised platform staff (D32 §2),
 * who configure the storefront by hand. Nothing here can create or reveal a
 * secret: only an identifier and the last few characters are ever returned.
 * Retrying a delivery and synchronising stay with the owner.
 */
class WebsiteIntegrationController extends MobileController
{
    use ResolvesOwnWebsite;

    public function __construct(
        protected WebsiteOverview $overview,
    ) {}

    /**
     * Storefront integration
     *
     * The record of the calls the storefront has made, which credentials it holds (key
     * id and a hint of the secret only — **a secret is never returned**), the webhook
     * address, the delivery record and the dead-letter queue of deliveries a person has
     * to retry. `403` without the right to manage the website.
     */
    public function show(Request $request, string $website): WebsiteIntegrationResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return new WebsiteIntegrationResource([
            'credentials' => WebsiteCredential::query()->where('website_id', $record->id)->orderByDesc('id')->get(),
            'recent_calls' => ApiLog::query()->where('website_id', $record->id)->latest('id')->limit(20)->get(),
            'webhook' => WebsiteWebhookEndpoint::query()->where('website_id', $record->id)->first(),
            'deliveries' => WebhookDelivery::query()->where('website_id', $record->id)->latest('id')->limit(20)->get(),
            'failures' => SyncQueueFailure::query()
                ->where('website_id', $record->id)
                ->unresolved()
                ->with('delivery')
                ->latest('id')
                ->get(),
        ]);
    }

    /**
     * Retry a delivery
     *
     * Sends a failed webhook delivery again with the same event id and returns the
     * integration record. `delivery` is the `event_id`. `403` without the right to
     * manage the website, `404` for a delivery that is not this website's, `422` when it
     * cannot be retried.
     */
    public function retryDelivery(Request $request, string $website, string $delivery, RetryWebhookDelivery $retry): WebsiteIntegrationResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        /** @var WebhookDelivery|null $found */
        $found = WebhookDelivery::query()
            ->where('website_id', $record->id)
            ->where('event_id', $delivery)
            ->first();

        abort_if($found === null, 404);

        $this->attempt(fn () => $retry->handle($found, $this->person($request)));

        return $this->show($request, $website);
    }

    /**
     * Synchronise now
     *
     * Tells the storefront about everything now (manual mode): queues the catalogue and
     * inventory for every published product. Once a minute per website — a second press
     * inside the minute is `422`. `403` without the right to manage the website.
     *
     * @response array{data: WebsiteDetailResource}
     */
    public function syncNow(Request $request, string $website, SyncWebsiteCatalogue $catalogue, SyncWebsiteInventory $inventory): JsonResponse
    {
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

        return new JsonResponse(['data' => (new WebsiteDetailResource($this->overview->detail($record->refresh())))->resolve($request)], 202);
    }
}
