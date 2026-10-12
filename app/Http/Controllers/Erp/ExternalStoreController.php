<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreOverview;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A partner's previously connected stores on other platforms, read-only (D29,
 * as amended by D30).
 *
 * Owners no longer connect, test, sync, re-key or disconnect stores themselves:
 * their websites are served by Feriwala's own storefront and take orders through
 * the Storefront API. What was connected before stays visible, with its orders,
 * and the integration itself — the actions, the sync, the webhook endpoint —
 * is untouched.
 *
 * **Self-scoped by construction.** Every store is found through the signed-in
 * person's own account, so another partner's store is a 404, never a refusal.
 * No page prop carries a credential or a secret.
 */
class ExternalStoreController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected ExternalStoreOverview $overview,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ExternalStore::class);

        return Inertia::render('stores/index', [
            'stores' => $this->overview->forAccount($this->businessAccountFor($request)),
        ]);
    }

    public function show(Request $request, string $store): Response
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $store);

        abort_if($record === null, 404);

        Gate::authorize('view', $record);

        return Inertia::render('stores/show', [
            'store' => $this->overview->detail($record),
        ]);
    }
}
