<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Actions\ManageWebsiteCredentials;
use App\Domain\Website\Data\IssuedCredential;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\StorefrontEnvironmentBlock;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A website's Storefront API credentials, administered by authorised platform
 * staff only (D32 §2, P3 ER-1, ER-3, ER-4).
 *
 * The owner never issues, sees or handles a raw secret: an operator issues the
 * credential here and enters it into that storefront installation's
 * server-side environment by hand. Nothing is pushed to any server.
 *
 * **The secret travels once, in the JSON response that created it**, marked
 * `no-store`. It is never put in the session, a flash message, a page prop, the
 * audit trail or a log, and cannot be fetched again: losing it means rotating.
 *
 * Existing credentials are not touched by anything here unless an operator
 * rotates or revokes one, and a rotation keeps the previous secret valid for
 * its grace window, so a live storefront is never broken without warning.
 */
class WebsiteCredentialController extends Controller
{
    public function __construct(
        protected ManageWebsiteCredentials $credentials,
    ) {}

    public function store(Request $request, Website $website): JsonResponse
    {
        Gate::authorize('manageIntegration', $website);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::enum(CredentialScope::class)],
        ]);

        try {
            $issued = $this->credentials->issue(
                $website,
                $this->actor($request),
                $validated['name'],
                array_map(fn (string $scope) => CredentialScope::from($scope), $validated['scopes']),
            );
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->once($issued);
    }

    public function rotate(Request $request, Website $website, string $credential): JsonResponse
    {
        Gate::authorize('manageIntegration', $website);

        try {
            $issued = $this->credentials->rotate($this->credentialOf($website, $credential), $this->actor($request));
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return $this->once($issued);
    }

    public function revoke(Request $request, Website $website, string $credential): RedirectResponse
    {
        Gate::authorize('manageIntegration', $website);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->credentials->revoke($this->credentialOf($website, $credential), $this->actor($request), $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.credential_revoked')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * The one response that carries the secret.
     */
    protected function once(IssuedCredential $issued): JsonResponse
    {
        $origin = (string) config('app.url');

        return new JsonResponse([
            'key_id' => $issued->credential->key_id,
            'secret' => $issued->secret,
            'scopes' => $issued->credential->scopes,
            'env' => StorefrontEnvironmentBlock::for($issued, $origin),
            'origin_accepted' => StorefrontEnvironmentBlock::isAcceptedOrigin($origin),
            'previous_secret_expires_at' => $issued->credential->previous_secret_expires_at?->toIso8601String(),
        ], 201, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    protected function credentialOf(Website $website, string $publicId): WebsiteCredential
    {
        /** @var WebsiteCredential|null $credential */
        $credential = WebsiteCredential::query()
            ->where('website_id', $website->id)
            ->where('public_id', $publicId)
            ->first();

        abort_if($credential === null, 404);

        return $credential;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
