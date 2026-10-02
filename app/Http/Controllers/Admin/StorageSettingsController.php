<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Storage\Actions\ConfigureR2Storage;
use App\Domain\Storage\Actions\TestR2Connection;
use App\Domain\Storage\Policies\StorageSettingsPolicy;
use App\Domain\Storage\R2StorageSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Settings -> Storage: the Cloudflare R2 credentials and switch
 * (beta-critical batch, Commit 3).
 *
 * **No credential ever reaches the browser.** {@see index()} reports only
 * whether each secret is present (plus a masked last-four of the access key
 * ID) -- never the value. {@see update()} and {@see enable()} sit behind the
 * `RequirePassword` route middleware so reaching them at all means the
 * session just confirmed a password; {@see SensitiveActionGuard}
 * inside {@see ConfigureR2Storage} enforces that (and two-factor, and a
 * reason) again, so a job or a second controller reaching the action cannot
 * skip it.
 */
class StorageSettingsController extends Controller
{
    public function __construct(
        protected ConfigureR2Storage $configure,
        protected TestR2Connection $test,
    ) {}

    public function index(Request $request, R2StorageSettings $settings): Response
    {
        $actor = $this->actor($request);

        abort_unless(StorageSettingsPolicy::canView($actor), 403);

        return Inertia::render('admin/storage-settings/index', [
            'settings' => $this->payload($settings),
            'can' => ['manage' => StorageSettingsPolicy::canManage($actor)],
        ]);
    }

    /**
     * Store the R2 credentials. Tested against R2, live, before anything is
     * persisted -- see {@see ConfigureR2Storage::handle()}.
     */
    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(StorageSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'account_id' => ['nullable', 'string', 'max:255'],
            'access_key_id' => ['nullable', 'string', 'max:255'],
            'secret_access_key' => ['nullable', 'string', 'max:255'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:2048', 'url'],
            'region' => ['nullable', 'string', 'max:64'],
            'public_domain' => ['nullable', 'string', 'max:2048', 'url'],
            'default_visibility' => ['nullable', Rule::in(['public', 'private'])],
            'signed_url_expiry_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->configure->handle(
                actor: $actor,
                fields: $validated,
                // The route's RequirePassword middleware has already
                // established this. Passed explicitly so the action states
                // what it requires rather than trusting its caller.
                passwordConfirmed: true,
                twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
                reason: $validated['reason'],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['account_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('storage_settings.saved')]);

        return back();
    }

    /**
     * Switch R2 on or off.
     */
    public function enable(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(StorageSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->configure->setEnabled(
                actor: $actor,
                enabled: (bool) $validated['enabled'],
                passwordConfirmed: true,
                twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
                reason: $validated['reason'],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['enabled' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($validated['enabled'] ? 'storage_settings.enabled' : 'storage_settings.disabled'),
        ]);

        return back();
    }

    /**
     * Try a set of credentials against R2, live -- saved or not.
     *
     * A plain JSON endpoint rather than an Inertia visit: the settings form
     * stays exactly as the administrator left it while this runs.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless(StorageSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'account_id' => ['nullable', 'string', 'max:255'],
            'access_key_id' => ['nullable', 'string', 'max:255'],
            'secret_access_key' => ['nullable', 'string', 'max:255'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:2048'],
            'region' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->test->handle($validated);

        return response()->json($result->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(R2StorageSettings $settings): array
    {
        return [
            'enabled' => $settings->isEnabled(),
            'is_configured' => $settings->isConfigured(),
            'account_id' => $settings->accountId(),
            'access_key_id_masked' => $settings->maskedAccessKeyId(),
            'is_access_key_configured' => $settings->isAccessKeyConfigured(),
            'is_secret_configured' => $settings->isSecretConfigured(),
            'bucket' => $settings->bucket(),
            'endpoint' => $settings->endpoint(),
            'region' => $settings->region(),
            'public_domain' => $settings->publicDomain(),
            'default_visibility' => $settings->defaultVisibility(),
            'signed_url_expiry_minutes' => $settings->signedUrlExpiryMinutes(),
        ];
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
