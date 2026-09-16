<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Actions\MoveWebsiteStatus;
use App\Domain\Website\Actions\ProvisionWebsiteService;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'statuses' => array_map(fn (WebsiteStatus $status) => [
                'value' => $status->value,
                'label' => __('website.statuses.'.$status->value),
            ], WebsiteStatus::cases()),
        ]);
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

        return Inertia::render('admin/websites/show', [
            'website' => $this->overview->detail($website, forStaff: true),
            'can' => [
                'administer' => $request->user()?->can('administer', $website) ?? false,
            ],
            // Only the moves the map actually allows from where it stands, so
            // the screen cannot offer one the action would refuse.
            'transitions' => array_map(fn (WebsiteStatus $status) => [
                'value' => $status->value,
                'label' => __('website.statuses.'.$status->value),
            ], $website->status->transitionsTo()),
        ]);
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

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
