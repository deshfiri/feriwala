<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\ExternalStore\Actions\ConnectExternalStore;
use App\Domain\ExternalStore\Actions\DisconnectExternalStore;
use App\Domain\ExternalStore\Actions\RotateExternalStoreWebhookSecret;
use App\Domain\ExternalStore\Actions\TestExternalStoreConnection;
use App\Domain\ExternalStore\Actions\UpdateExternalStoreConnection;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use App\Domain\ExternalStore\Enums\StorePlatform;
use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Queries\ExternalStoreAllowance;
use App\Domain\ExternalStore\Queries\ExternalStoreOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A partner's connections to their own stores on other platforms (D29).
 *
 * **Self-scoped by construction.** Every store is found through the signed-in
 * person's own account, so another partner's store is a 404, never a refusal.
 * Credentials go in and never come back out: no page prop, flash or error
 * carries a key or a secret. The one exception is the webhook secret, shown
 * once as flash data on the response that made it.
 *
 * Calls to the store (connecting, testing, syncing) are rate-limited per person,
 * and a sync is always queued.
 */
class ExternalStoreController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected ExternalStoreOverview $overview,
        protected ExternalStoreAllowance $allowance,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ExternalStore::class);

        $account = $this->businessAccountFor($request);

        return Inertia::render('stores/index', [
            'stores' => $this->overview->forAccount($account),
            'allowance' => $this->allowance->for($account),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', ExternalStore::class);

        return Inertia::render('stores/create', [
            'allowance' => $this->allowance->for($this->businessAccountFor($request)),
            'platforms' => $this->platforms(),
        ]);
    }

    /**
     * Check the address and credentials without saving anything — the step
     * before the partner confirms the connection.
     */
    public function check(Request $request, ConnectExternalStore $connect): RedirectResponse
    {
        Gate::authorize('create', ExternalStore::class);

        $validated = $this->validateConnection($request);

        $this->attempt(fn () => $connect->check(
            $this->businessAccountFor($request),
            StorePlatform::from($validated['platform']),
            $validated['base_url'],
            $validated['api_key'],
            $validated['api_secret'],
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('stores.flash.check_passed')]);

        return back();
    }

    public function store(Request $request, ConnectExternalStore $connect): RedirectResponse
    {
        Gate::authorize('create', ExternalStore::class);

        $validated = $this->validateConnection($request);

        /** @var array{store: ExternalStore, webhook_secret: string} $result */
        $result = $this->attempt(fn () => $connect->handle(
            $this->businessAccountFor($request),
            $this->person($request),
            StorePlatform::from($validated['platform']),
            $validated['name'],
            $validated['base_url'],
            $validated['api_key'],
            $validated['api_secret'],
        ));

        Inertia::flash('webhook_secret', $result['webhook_secret']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('stores.flash.connected')]);

        return to_route('stores.show', $result['store']->public_id);
    }

    public function show(Request $request, string $store): Response
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('view', $record);

        return Inertia::render('stores/show', [
            'store' => $this->overview->detail($record),
        ]);
    }

    public function edit(Request $request, string $store): Response
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('manage', $record);

        return Inertia::render('stores/edit', [
            'store' => $this->overview->detail($record),
        ]);
    }

    public function update(Request $request, string $store, UpdateExternalStoreConnection $update): RedirectResponse
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var array{store: ExternalStore, webhook_secret: string|null} $result */
        $result = $this->attempt(fn () => $update->handle(
            $record,
            $this->person($request),
            $validated['name'],
            $validated['api_key'] ?? null,
            $validated['api_secret'] ?? null,
        ));

        if ($result['webhook_secret'] !== null) {
            Inertia::flash('webhook_secret', $result['webhook_secret']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['webhook_secret'] !== null
            ? __('stores.flash.reconnected')
            : __('stores.flash.updated')]);

        return to_route('stores.show', $record->public_id);
    }

    public function test(Request $request, string $store, TestExternalStoreConnection $test): RedirectResponse
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('manage', $record);

        /** @var array{ok: bool, message: string|null} $result */
        $result = $this->attempt(fn () => $test->handle($record));

        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => __('stores.flash.test_passed')]
            : ['type' => 'error', 'message' => __('stores.flash.test_failed', ['reason' => (string) $result['message']])]);

        return back();
    }

    public function sync(Request $request, string $store): RedirectResponse
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('manage', $record);

        if (! $record->status->isLive()) {
            throw ValidationException::withMessages(['store' => __('stores.refused.not_live')]);
        }

        SyncExternalStore::dispatch($record->id, ExternalStoreStatusChangeSource::Account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('stores.flash.sync_queued')]);

        return back();
    }

    public function rotateWebhookSecret(Request $request, string $store, RotateExternalStoreWebhookSecret $rotate): RedirectResponse
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('manage', $record);

        $secret = $this->attempt(fn () => $rotate->handle($record, $this->person($request)));

        Inertia::flash('webhook_secret', $secret);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('stores.flash.webhook_rotated')]);

        return back();
    }

    public function destroy(Request $request, string $store, DisconnectExternalStore $disconnect): RedirectResponse
    {
        $record = $this->storeFor($request, $store);
        Gate::authorize('manage', $record);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $disconnect->handle($record, $this->person($request), $validated['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('stores.flash.disconnected')]);

        return to_route('stores.show', $record->public_id);
    }

    /**
     * @return array{platform: string, name: string, base_url: string, api_key: string, api_secret: string}
     */
    protected function validateConnection(Request $request): array
    {
        /** @var array{platform: string, name: string, base_url: string, api_key: string, api_secret: string} $validated */
        $validated = $request->validate([
            'platform' => ['required', Rule::enum(StorePlatform::class)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'base_url' => ['required', 'string', 'max:255', 'url:https'],
            'api_key' => ['required', 'string', 'max:255'],
            'api_secret' => ['required', 'string', 'max:255'],
        ], [
            // Plain HTTP and malformed addresses get the same plain answer.
            'base_url.url' => __('stores.refused.address_invalid'),
        ]);

        return $validated;
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
        } catch (ExternalStoreRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function platforms(): array
    {
        return array_map(fn (StorePlatform $platform) => [
            'value' => $platform->value,
            'label' => $platform->label(),
        ], StorePlatform::cases());
    }

    protected function storeFor(Request $request, string $store): ExternalStore
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $store);

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
