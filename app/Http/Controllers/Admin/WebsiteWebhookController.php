<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A website's webhook endpoint and its signing secret (contract §7.2, P5-20),
 * administered by authorised platform staff only (D32 §2, Option P1).
 *
 * Like an API credential, the signing secret travels once, in the JSON response
 * that issued it, marked `no-store`. It is never put in the session, a flash
 * message, a page prop, the audit trail or a log. The owner sees the address,
 * its last four characters and the delivery record, and never the secret.
 *
 * Nothing here touches an existing secret unless an operator rotates it, and a
 * rotation keeps the previous secret valid for its grace window.
 */
class WebsiteWebhookController extends Controller
{
    public function __construct(
        protected ManageWebhookEndpoint $webhooks,
    ) {}

    /**
     * Set the address, issuing a signing secret the first time only.
     */
    public function store(Request $request, Website $website): JsonResponse
    {
        Gate::authorize('manageIntegration', $website);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:https'],
        ]);

        try {
            $result = $this->webhooks->configure($website, $this->actor($request), $validated['url']);
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->answer($result['endpoint'], $result['secret']);
    }

    public function rotate(Request $request, Website $website): JsonResponse
    {
        Gate::authorize('manageIntegration', $website);

        $endpoint = $this->endpointOf($website);
        $secret = $this->webhooks->rotate($endpoint, $this->actor($request));

        return $this->answer($endpoint, $secret);
    }

    public function disable(Request $request, Website $website): RedirectResponse
    {
        Gate::authorize('manageIntegration', $website);

        $this->webhooks->disable($this->endpointOf($website), $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.webhook_disabled')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * The one response that may carry the signing secret.
     */
    protected function answer(WebsiteWebhookEndpoint $endpoint, ?string $secret): JsonResponse
    {
        return new JsonResponse([
            'url' => $endpoint->url,
            'secret' => $secret,
            'secret_hint' => $endpoint->secret_hint,
            'previous_secret_expires_at' => $endpoint->previous_secret_expires_at?->toIso8601String(),
        ], 200, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    protected function endpointOf(Website $website): WebsiteWebhookEndpoint
    {
        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()->with('website')->where('website_id', $website->id)->first();

        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
