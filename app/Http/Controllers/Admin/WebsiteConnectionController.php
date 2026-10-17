<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Actions\ConnectStore;
use App\Domain\Website\Data\ConnectedStore;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Connecting a partner's storefront in one step, from the admin dashboard, by
 * platform staff authorised to manage integrations (D32 §2).
 *
 * The one response carries the store id and the token, once, marked `no-store`.
 * Neither is ever put in the session, a flash message, a prop, the audit trail
 * or a log.
 */
class WebsiteConnectionController extends Controller
{
    public function __construct(
        protected ConnectStore $connect,
    ) {}

    public function store(Request $request, Website $website): JsonResponse
    {
        Gate::authorize('manageIntegration', $website);

        $validated = $request->validate([
            // Free text on purpose: a domain, a URL, or — on a developer's own
            // machine — http://127.0.0.1:8001. ConnectStore decides what it is.
            'address' => ['required', 'string', 'max:253'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        try {
            $connected = $this->connect->handle(
                $website,
                $this->actor($request),
                $validated['address'],
                (int) ($validated['term_months'] ?? 12),
            );
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->once($connected, 201);
    }

    /**
     * A new token for a store that is already connected.
     */
    public function rotate(Request $request, Website $website): JsonResponse
    {
        Gate::authorize('manageIntegration', $website);

        try {
            $connected = $this->connect->rotate($website, $this->actor($request));
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->once($connected, 200);
    }

    /**
     * End the connection. A reason is required: a storefront going dark is
     * something its owner will ask about.
     */
    public function destroy(Request $request, Website $website): RedirectResponse
    {
        Gate::authorize('manageIntegration', $website);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->connect->disconnect($website, $this->actor($request), $validated['reason']);
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.connect.disconnected')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * The one response that carries the token.
     */
    protected function once(ConnectedStore $connected, int $status): JsonResponse
    {
        return new JsonResponse([
            'store_id' => $connected->storeId(),
            'token' => $connected->token,
            'address' => $connected->address,
            'local' => $connected->local,
            'webhook_url' => $connected->webhookUrl,
            'webhook_secret' => $connected->webhookSecret,
            'erp_base_url' => $connected->erpBaseUrl,
            'env' => $connected->env(),
        ], $status, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
