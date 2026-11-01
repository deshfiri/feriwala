<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\RotateSteadfastWebhookToken;
use App\Domain\Courier\Actions\TestSteadfastConnection;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Policies\CourierSettingsPolicy;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Integrations\Courier\Steadfast\SteadfastCredentials;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Steadfast's keys, its on/off switch, the callback details to enter in its
 * merchant panel, and Test connection.
 *
 * No saved credential is ever sent to the browser: the screen is told whether
 * each is present and what is still missing. The one exception is a freshly
 * generated webhook token, flashed once so staff can copy it into Steadfast.
 */
class SteadfastSettingsController extends Controller
{
    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        /** @var SteadfastCredentials $credentials */
        $credentials = app(SteadfastCredentials::class);

        return Inertia::render('admin/courier/steadfast', [
            'settings' => [
                'enabled' => (bool) CourierProvider::query()
                    ->where('code', CourierProviderCode::Steadfast->value)
                    ->value('is_enabled'),
                'configured' => $credentials->areConfigured(),
                'missing' => $credentials->missing(),
                'has_api_key' => $credentials->apiKey() !== null,
                'has_secret_key' => $credentials->secretKey() !== null,
                'has_webhook_token' => $credentials->webhookToken() !== null,
                'base_url' => $credentials->baseUrl(),
                'default_base_url' => config('courier.steadfast.default_base_url'),
                'last_test_at' => $this->settings->get(SteadfastCredentials::LAST_TEST_AT),
                'last_test_ok' => (bool) $this->settings->get(SteadfastCredentials::LAST_TEST_OK, false),
            ],
            // What staff type into Steadfast's merchant panel (Callback Url, Auth Token).
            'callback_url' => url('webhooks/courier/steadfast'),
            'can' => ['manage' => true],
        ]);
    }

    public function update(Request $request, ConfigureSteadfast $configure): RedirectResponse
    {
        $actor = $this->authorizeManage($request);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'secret_key' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $configure->handle($actor, (bool) $validated['enabled'], $validated['base_url'] ?? null, [
                'api_key' => $validated['api_key'] ?? null,
                'secret_key' => $validated['secret_key'] ?? null,
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['base_url' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('steadfast.saved')]);

        return back();
    }

    /**
     * The one response that carries the token: JSON, marked `no-store`, held by
     * the page only in memory. Never a flash, a session value, a prop or a log
     * -- the same rule as a storefront's signing secret.
     */
    public function rotateWebhookToken(Request $request, RotateSteadfastWebhookToken $rotate): JsonResponse
    {
        $actor = $this->authorizeManage($request);

        return new JsonResponse(['token' => $rotate->handle($actor)], 200, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function test(Request $request, TestSteadfastConnection $test): RedirectResponse
    {
        $actor = $this->authorizeManage($request);

        $result = $test->handle($actor);

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('steadfast.test.ok', ['balance' => $result->balance?->format()])]
            : ['type' => 'error', 'message' => __('steadfast.test.failed', ['reason' => $result->failure?->message()])]);

        return back();
    }

    protected function authorizeManage(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User && CourierSettingsPolicy::canManage($actor), 403);

        return $actor;
    }
}
