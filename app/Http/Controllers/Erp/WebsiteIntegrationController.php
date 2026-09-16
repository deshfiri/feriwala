<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Website\Actions\ManageWebsiteCredentials;
use App\Domain\Website\Data\IssuedCredential;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\ApiLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Queries\WebsiteOverview;
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
 * Connecting a storefront to the ERP (§17.3, contract §3, §9, P5-17, P5-27, P5-28).
 *
 * The partner's own website's credentials — issued, rotated, revoked — and the
 * record of the calls its storefront has made. Self-scoped like every other
 * website screen.
 *
 * **A secret is shown exactly once**, in the response to issuing or rotating it,
 * as flash data that is gone by the next page load. It is never a page prop a
 * reload could bring back, and never in a log.
 */
class WebsiteIntegrationController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
    ) {}

    public function show(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return Inertia::render('websites/integration', [
            'website' => $this->overview->detail($record),
            'credentials' => WebsiteCredential::query()
                ->where('website_id', $record->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (WebsiteCredential $credential) => [
                    'id' => $credential->public_id,
                    'name' => $credential->name,
                    'key_id' => $credential->key_id,
                    'secret_hint' => $credential->secret_hint,
                    'scopes' => $credential->scopes,
                    'last_used_at' => $credential->last_used_at?->toIso8601String(),
                    'rotated_at' => $credential->rotated_at?->toIso8601String(),
                    'previous_secret_expires_at' => $credential->previous_secret_expires_at?->toIso8601String(),
                    'revoked_at' => $credential->revoked_at?->toIso8601String(),
                    'revoked_reason' => $credential->revoked_reason,
                    'created_at' => $credential->created_at->toIso8601String(),
                ])
                ->all(),
            'scopes' => array_map(fn (CredentialScope $scope) => [
                'value' => $scope->value,
                'label' => __('website.integration.scopes.'.$scope->value),
                'default' => in_array($scope, CredentialScope::defaults(), true),
            ], CredentialScope::cases()),

            // The last calls this storefront made, as they were answered. The
            // log was redacted before it was stored; nothing here is a secret.
            'recent_calls' => ApiLog::query()
                ->where('website_id', $record->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ApiLog $log) => [
                    'request_id' => $log->request_id,
                    'method' => $log->method,
                    'path' => $log->path,
                    'status' => $log->status,
                    'error_code' => $log->error_code,
                    'duration_ms' => $log->duration_ms,
                    'created_at' => $log->created_at->toIso8601String(),
                ])
                ->all(),
            'api_base' => url('/api/storefront/v1'),
        ]);
    }

    public function storeCredential(Request $request, string $website, ManageWebsiteCredentials $credentials): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::enum(CredentialScope::class)],
        ]);

        $issued = $this->attempt(fn () => $credentials->issue(
            $record,
            $this->person($request),
            $validated['name'],
            array_map(fn (string $scope) => CredentialScope::from($scope), $validated['scopes']),
        ));

        $this->showOnce($issued);

        return to_route('websites.integration.show', $record->public_id);
    }

    public function rotateCredential(
        Request $request,
        string $website,
        string $credential,
        ManageWebsiteCredentials $credentials,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $issued = $this->attempt(fn () => $credentials->rotate(
            $this->credentialFor($record, $credential),
            $this->person($request),
        ));

        $this->showOnce($issued);

        return to_route('websites.integration.show', $record->public_id);
    }

    public function revokeCredential(
        Request $request,
        string $website,
        string $credential,
        ManageWebsiteCredentials $credentials,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $credentials->revoke(
            $this->credentialFor($record, $credential),
            $this->person($request),
            $validated['reason'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.credential_revoked')]);

        return to_route('websites.integration.show', $record->public_id);
    }

    /**
     * Hand the secret to the one response that may carry it.
     */
    protected function showOnce(IssuedCredential $issued): void
    {
        Inertia::flash('credential', [
            'key_id' => $issued->credential->key_id,
            'secret' => $issued->secret,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.credential_issued')]);
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
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }

    protected function credentialFor(Website $website, string $credential): WebsiteCredential
    {
        /** @var WebsiteCredential|null $record */
        $record = WebsiteCredential::query()
            ->with('website')
            ->where('website_id', $website->id)
            ->where('public_id', $credential)
            ->first();

        abort_if($record === null, 404);

        return $record;
    }

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $website);

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
