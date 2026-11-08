<?php

namespace App\Http\Controllers\Api\Mobile\V1\Orders;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A partner's previously connected stores on other platforms, read-only (D29,
 * as amended by D30).
 *
 * **Self-scoped by construction.** Every store is found through the signed-in
 * person's own account, so another partner's store is a 404, never a refusal.
 * No payload carries a credential or a secret: the key is shown by its last
 * four characters, the secrets not at all.
 */
class ExternalStoreController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected ExternalStoreOverview $overview,
    ) {}

    /**
     * List connected stores
     *
     * The stores this account connected before, with their status and order counts. Read-only:
     * connecting, syncing and disconnecting are not offered.
     *
     * @response array{data: list<array<string, mixed>>}
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ExternalStore::class);

        return new JsonResponse([
            'data' => $this->overview->forAccount($this->businessAccountFor($request)),
        ]);
    }

    /**
     * Connected store detail
     *
     * One store with its connection history and recent webhook events. `404` for another
     * account's store.
     *
     * @response array{data: array<string, mixed>}
     */
    public function show(Request $request, string $store): JsonResponse
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $store);

        abort_if($record === null, 404);

        Gate::authorize('view', $record);

        return new JsonResponse(['data' => $this->overview->detail($record)]);
    }
}
