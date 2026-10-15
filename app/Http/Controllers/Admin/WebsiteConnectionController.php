<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Actions\ConnectStore;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

        $actor = $request->user();
        abort_if(! $actor instanceof User, 403);

        try {
            $connected = $this->connect->handle(
                $website,
                $actor,
                $validated['address'],
                (int) ($validated['term_months'] ?? 12),
            );
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return new JsonResponse([
            'store_id' => $connected->storeId(),
            'token' => $connected->token,
            'address' => $connected->address,
            'local' => $connected->local,
            'webhook_url' => $connected->webhookUrl,
            'webhook_secret' => $connected->webhookSecret,
            'erp_base_url' => $connected->erpBaseUrl,
            'env' => $connected->env(),
        ], 201, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
