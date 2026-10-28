<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Website\Actions\ManageWebsiteBranding;
use App\Domain\Website\Actions\ManageWebsiteDomainRouting;
use App\Domain\Website\Actions\MoveWebsiteStatus;
use App\Domain\Website\Actions\ProvisionWebsiteService;
use App\Domain\Website\Actions\RequestWebsite;
use App\Domain\Website\Enums\CredentialPreset;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Domain\Website\RequestedAddress;
use App\Domain\Website\StorefrontEnvironmentBlock;
use App\Domain\Website\WebsiteAddresses;
use App\Domain\Website\WebsiteImageStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner websites, for the staff who run the platform (§16.3, §16.4, P5-9, P5-11).
 *
 * Read behind `website.view`; moving a website through §16.4 and recording what
 * was provisioned are `website.edit`. A partner holds neither — the ordered
 * `Gate::before` refuses `website.*` to a business identity outright, so this
 * panel is not reachable by a partner who has been handed a permission by
 * mistake.
 *
 * Every move here carries a reason. A storefront going dark is something its
 * owner will ask about, and "suspended" with nothing beside it is not an answer
 * anybody can give them.
 */
class WebsiteController extends Controller
{
    /** Staff must say why, at least this fully. */
    protected const MINIMUM_REASON = 10;

    public function __construct(
        protected WebsiteOverview $overview,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Website::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        $websites = $this->overview->paginate($filters);

        return Inertia::render('admin/websites/index', [
            // The whole paginator, so the table has its range and page size.
            'websites' => $websites->through(fn (Website $website) => $this->overview->summary($website)),
            'filters' => $filters,
            'can' => ['provision' => $request->user()?->can('provision', Website::class) ?? false],
            'statuses' => array_map(fn (WebsiteStatus $status) => [
                'value' => $status->value,
                'label' => __('website.statuses.'.$status->value),
            ], WebsiteStatus::cases()),
        ]);
    }

    /**
     * The form for standing a website up on a partner's behalf.
     */
    public function create(): Response
    {
        Gate::authorize('provision', Website::class);

        return Inertia::render('admin/websites/create', [
            'accounts' => BusinessAccount::query()
                ->orderBy('name')
                ->get(['id', 'public_id', 'name', 'status'])
                ->filter(fn (BusinessAccount $account) => $account->canTransact())
                ->map(fn (BusinessAccount $account) => [
                    'id' => $account->public_id,
                    'name' => $account->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Stand a website up for a partner.
     *
     * The same action the partner's own request runs, so the package's website
     * limit, its slot and the setup, domain and hosting charges are exactly what
     * they would have been had the partner asked: staff create the website, they
     * do not waive what it costs. A partner at their limit closes one first.
     */
    public function store(Request $request, RequestWebsite $requestWebsite): RedirectResponse
    {
        Gate::authorize('provision', Website::class);

        $validated = $request->validate([
            'account' => ['required', 'string', Rule::exists('business_accounts', 'public_id')],
            'name' => ['required', 'string', 'max:120'],
            // Whatever staff type, taken as it is: no shape, reserved-name or
            // uniqueness check. It is only normalised so the column accepts it.
            'domain' => ['required', 'string', 'max:253'],
        ]);

        /** @var BusinessAccount $account */
        $account = BusinessAccount::query()->where('public_id', $validated['account'])->firstOrFail();

        $domain = RequestedAddress::normalize($validated['domain']);

        $website = $this->attemptRouting(fn () => $requestWebsite->handle(
            $account,
            $this->person($request),
            $validated['name'],
            RequestedAddress::label($domain),
        ));

        try {
            // Its own savepoint: PostgreSQL aborts the whole transaction on a
            // failed statement, and the duplicate is the one failure expected.
            DB::transaction(fn () => $website->forceFill(['domain' => $domain])->save());
        } catch (UniqueConstraintViolationException) {
            // Another website already holds this domain. The website still
            // exists; the address is kept for staff to sort out.
            $website->forceFill(['domain' => null, 'requested_address' => $domain])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.created_for_partner')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Delete a website: close it, for good.
     *
     * Its charges, orders, credentials and status history are financial and
     * audit records that are never removed, and the database refuses to drop a
     * website they point at. Closing is the end of its life — the storefront
     * stops answering, the credentials stop working, and the package slot it
     * held is free for the partner's next website.
     */
    public function destroy(Request $request, Website $website, MoveWebsiteStatus $move): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.self::MINIMUM_REASON, 'max:500'],
        ]);

        $this->attemptRouting(fn () => $move->handle(
            $website,
            WebsiteStatus::Closed,
            WebsiteStatusChangeSource::Staff,
            new StatusChange(actorId: $this->person($request)->id, reason: $validated['reason']),
            ['closed_at' => CarbonImmutable::now()],
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.deleted')]);

        return to_route('admin.websites.index');
    }

    public function show(Request $request, Website $website): Response
    {
        /*
         * `viewAny`, not `view`. The website policy's `view` is true for the
         * account that owns the storefront — correctly, on their own page — and
         * this screen carries what the platform wrote *about* them: internal
         * notes, who decided, the registrar and the host. Reaching for the
         * obvious name is how the account dossier once showed an owner the
         * reviewer's private notes about their own application.
         */
        Gate::authorize('viewAny', Website::class);

        $managesIntegration = $request->user()?->can('manageIntegration', $website) ?? false;

        return Inertia::render('admin/websites/show', [
            'website' => $this->overview->detail($website, forStaff: true),
            'can' => [
                'administer' => $request->user()?->can('administer', $website) ?? false,
                'manage_integration' => $managesIntegration,
            ],
            'image_limits' => [
                'max_bytes' => WebsiteImageStore::MAX_BYTES,
                'accepted' => WebsiteImageStore::ACCEPTED_MIME_TYPES,
            ],

            // The storefront's API credentials (D32 §2), for those who may
            // manage them and nobody else. Never a secret: the key id and its
            // last four characters say which one it is.
            'credentials' => $managesIntegration ? $this->credentialsOf($website) : [],
            'webhook' => $managesIntegration ? $this->webhookOf($website) : null,
            'credential_scopes' => $managesIntegration ? array_map(fn (CredentialScope $scope) => [
                'value' => $scope->value,
                'label' => __('website.integration.scopes.'.$scope->value),
            ], CredentialScope::cases()) : [],
            'credential_presets' => $managesIntegration ? array_map(fn (CredentialPreset $preset) => [
                'value' => $preset->value,
                'label' => $preset->label(),
                'scopes' => array_map(fn (CredentialScope $scope) => $scope->value, $preset->scopes()),
            ], CredentialPreset::cases()) : [],
            'storefront_connection' => $managesIntegration ? [
                'erp_origin' => StorefrontEnvironmentBlock::origin((string) config('app.url')),
                'origin_accepted' => StorefrontEnvironmentBlock::isAcceptedOrigin((string) config('app.url')),
                // What the storefront keeps in its public site.config.json.
                'website_id' => $website->public_id,
                'primary_host' => app(WebsiteAddresses::class)->hosts($website)[0]
                    ?? $website->subdomain.'.'.config('website.storefront_domain'),
            ] : null,
            // Only the moves the map actually allows from where it stands, so
            // the screen cannot offer one the action would refuse.
            'transitions' => array_map(fn (WebsiteStatus $status) => [
                'value' => $status->value,
                'label' => __('website.statuses.'.$status->value),
            ], $website->status->transitionsTo()),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function credentialsOf(Website $website): array
    {
        return WebsiteCredential::query()
            ->where('website_id', $website->id)
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
            ->values()
            ->all();
    }

    /**
     * The webhook endpoint by its address and the last four characters of its
     * signing secret, never the secret.
     *
     * @return array{url: string, secret_hint: string, is_active: bool, rotated_at: string|null, previous_secret_expires_at: string|null}|null
     */
    protected function webhookOf(Website $website): ?array
    {
        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()->where('website_id', $website->id)->first();

        return $endpoint === null ? null : [
            'url' => $endpoint->url,
            'secret_hint' => $endpoint->secret_hint,
            'is_active' => $endpoint->is_active,
            'rotated_at' => $endpoint->rotated_at?->toIso8601String(),
            'previous_secret_expires_at' => $endpoint->previous_secret_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Move a website through §16.4 by hand.
     */
    public function updateStatus(Request $request, Website $website, MoveWebsiteStatus $move): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(WebsiteStatus::class)],
            'reason' => ['required', 'string', 'min:'.self::MINIMUM_REASON, 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'public_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $target = WebsiteStatus::from($validated['status']);
        $now = CarbonImmutable::now();

        $columns = match ($target) {
            WebsiteStatus::Active => ['activated_at' => $website->activated_at ?? $now, 'suspended_at' => null, 'suspension_reason' => null],
            WebsiteStatus::Suspended => ['suspended_at' => $now, 'suspension_reason' => $validated['reason']],
            WebsiteStatus::Closed => ['closed_at' => $now],
            default => [],
        };

        try {
            $move->handle(
                $website,
                $target,
                WebsiteStatusChangeSource::Staff,
                new StatusChange(
                    actorId: $this->person($request)->id,
                    reason: $validated['reason'],
                    internalNote: $validated['internal_note'] ?? null,
                    publicNote: $validated['public_note'] ?? null,
                ),
                $columns,
            );
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.status_changed')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Put a logo or a banner on a partner's storefront on their behalf.
     */
    public function updateImage(
        Request $request,
        Website $website,
        string $asset,
        ManageWebsiteBranding $branding,
    ): RedirectResponse {
        Gate::authorize('administer', $website);

        $request->validate([
            'image' => [
                'required', 'file',
                'mimetypes:'.implode(',', WebsiteImageStore::ACCEPTED_MIME_TYPES),
                'max:'.(int) (WebsiteImageStore::MAX_BYTES / 1024),
            ],
        ]);

        $file = $request->file('image');

        abort_if(! $file instanceof UploadedFile, 422);

        $this->attemptRouting(fn () => $branding->putImage($website, $this->person($request), $asset, $file));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.image_saved')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    public function destroyImage(
        Request $request,
        Website $website,
        string $asset,
        ManageWebsiteBranding $branding,
    ): RedirectResponse {
        Gate::authorize('administer', $website);

        $this->attemptRouting(fn () => $branding->removeImage($website, $this->person($request), $asset));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.image_removed')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Record a domain somebody has registered for this website (D9, P5-11).
     */
    public function storeDomain(Request $request, Website $website, ProvisionWebsiteService $provision): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i',
            ],
            'registrar' => ['nullable', 'string', 'max:120'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'make_primary' => ['nullable', 'boolean'],
        ]);

        try {
            $provision->registerDomain(
                $website,
                $this->person($request),
                $validated['domain'],
                (int) $validated['term_months'],
                $validated['registrar'] ?? null,
                makePrimary: (bool) ($validated['make_primary'] ?? true),
            );
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.domain_recorded')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Record a hosting term somebody has stood up (D9, P5-11).
     */
    public function storeHosting(Request $request, Website $website, ProvisionWebsiteService $provision): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $provision->startHosting(
            $website,
            $this->person($request),
            $validated['plan'],
            (int) $validated['term_months'],
            $validated['provider'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.hosting_recorded')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Issue (or reissue) the DNS token that proves control of a domain (D30 §3).
     */
    public function issueDomainVerification(Request $request, Website $website, int $domain, ManageWebsiteDomainRouting $routing): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $this->attemptRouting(fn () => $routing->issueVerification($this->domainOf($website, $domain), $this->person($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.domain_verification_issued')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Look for the token in the domain's DNS now. Verified only on evidence.
     */
    public function checkDomainVerification(Request $request, Website $website, int $domain, ManageWebsiteDomainRouting $routing): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $verified = $this->attemptRouting(fn () => $routing->verify($this->domainOf($website, $domain), $this->person($request)));

        Inertia::flash('toast', $verified
            ? ['type' => 'success', 'message' => __('website.flash.domain_verified')]
            : ['type' => 'error', 'message' => __('website.flash.domain_not_verified')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Put a verified domain on the storefront.
     */
    public function activateDomain(Request $request, Website $website, int $domain, ManageWebsiteDomainRouting $routing): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $this->attemptRouting(fn () => $routing->activate($this->domainOf($website, $domain), $this->person($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.domain_activated')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * Take an active domain off the storefront, with a reason.
     */
    public function suspendDomain(Request $request, Website $website, int $domain, ManageWebsiteDomainRouting $routing): RedirectResponse
    {
        Gate::authorize('administer', $website);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.self::MINIMUM_REASON, 'max:500'],
        ]);

        $this->attemptRouting(fn () => $routing->suspend($this->domainOf($website, $domain), $this->person($request), $validated['reason']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.domain_suspended')]);

        return to_route('admin.websites.show', $website->public_id);
    }

    /**
     * A domain of this website, and only this website: another website's domain
     * is a 404, never a refusal.
     */
    protected function domainOf(Website $website, int $domain): WebsiteDomain
    {
        /** @var WebsiteDomain $record */
        $record = $website->domains()->whereKey($domain)->firstOrFail();

        return $record;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $step
     * @return TReturn
     */
    protected function attemptRouting(callable $step): mixed
    {
        try {
            return $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
