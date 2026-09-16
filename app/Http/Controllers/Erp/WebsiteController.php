<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Wallet\DepositGuard;
use App\Domain\Website\Actions\MoveWebsiteStatus;
use App\Domain\Website\Actions\RenewWebsiteService;
use App\Domain\Website\Actions\RequestWebsite;
use App\Domain\Website\Actions\SettleWebsiteCharge;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An account's own dedicated websites (§16, P5-8–P5-11, P5-15).
 *
 * **Self-scoped through the signed-in person's own account** (§31.3). No
 * account appears in any URL, and a website is found among this account's or
 * not at all — so another partner's identifier is a 404 rather than a refusal
 * that confirms their shop exists.
 *
 * Nothing here decides what anything costs. Charges were priced when the
 * website was asked for and are paid out of the wallet; the browser sends which
 * charge, never how much.
 */
class WebsiteController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
        protected Entitlements $entitlements,
        protected DepositGuard $guard,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        return Inertia::render('websites/index', [
            'websites' => $this->overview->forAccount($account),
            'entitlement' => $this->entitlement($account),
            'can' => [
                'create' => $request->user()?->can('create', Website::class) ?? false,
            ],
        ]);
    }

    public function create(Request $request, RequestWebsite $quote): Response
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', Website::class);

        return Inertia::render('websites/create', [
            'entitlement' => $this->entitlement($account),
            'storefront_domain' => config('website.storefront_domain'),
            'wallet' => $this->walletFigures($account),

            // Priced by the same call that will raise the charges, so the
            // figure on this screen is the figure that gets charged (§9).
            'charges' => array_map(
                fn (Money $amount) => $amount->jsonSerialize(),
                $quote->quote($account),
            ),
        ]);
    }

    public function store(Request $request, RequestWebsite $request_website): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', Website::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'subdomain' => [
                'required', 'string', 'min:3', 'max:63',

                // The shape a hostname label may take: letters, digits and
                // hyphens, never starting or ending with one.
                'regex:/^[a-z0-9]([a-z0-9-]{1,61})?[a-z0-9]$/',
                Rule::notIn((array) config('website.reserved_subdomains', [])),
                Rule::unique('websites', 'subdomain'),
            ],
        ]);

        try {
            $website = $request_website->handle(
                $account,
                $this->person($request),
                $validated['name'],
                $validated['subdomain'],
            );
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.requested')]);

        return to_route('websites.show', $website->public_id);
    }

    public function show(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);
        $person = $this->person($request);

        return Inertia::render('websites/show', [
            'website' => $this->overview->detail($record),
            'wallet' => $this->walletFigures($record->businessAccount),
            'can' => [
                'pay' => $person->can('pay', $record),
                'manage' => $person->can('manage', $record),
            ],
        ]);
    }

    /**
     * Pay one outstanding charge out of the wallet (§24, P5-10).
     */
    public function payCharge(
        Request $request,
        string $website,
        string $charge,
        SettleWebsiteCharge $settle,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('pay', $record);

        /** @var WebsiteCharge|null $due */
        $due = WebsiteCharge::query()
            ->where('website_id', $record->id)
            ->where('public_id', $charge)
            ->first();

        abort_if($due === null, 404);

        $this->attempt(fn () => $settle->handle($due, $this->person($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.charge_paid')]);

        return to_route('websites.show', $record->public_id);
    }

    /**
     * Buy another term of the domain (§41, P5-11).
     */
    public function renewDomain(
        Request $request,
        string $website,
        int $domain,
        RenewWebsiteService $renew,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('pay', $record);

        /** @var WebsiteDomain|null $registration */
        $registration = WebsiteDomain::query()->where('website_id', $record->id)->find($domain);

        abort_if($registration === null, 404);

        $this->attempt(fn () => $renew->renewDomain(
            $registration,
            $this->person($request),
            (int) config('website.term_months', 12),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.renewed')]);

        return to_route('websites.show', $record->public_id);
    }

    /**
     * Buy another term of hosting (§41, P5-11).
     */
    public function renewHosting(
        Request $request,
        string $website,
        int $hosting,
        RenewWebsiteService $renew,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('pay', $record);

        /** @var WebsiteHosting|null $term */
        $term = WebsiteHosting::query()->where('website_id', $record->id)->find($hosting);

        abort_if($term === null, 404);

        $this->attempt(fn () => $renew->renewHosting(
            $term,
            $this->person($request),
            (int) config('website.term_months', 12),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.renewed')]);

        return to_route('websites.show', $record->public_id);
    }

    /**
     * Put the storefront into maintenance, or take it back out (§16.4, P5-15).
     *
     * The one §16.4 state the partner chooses for themselves. Everything else
     * happens to a website; this is somebody deciding to work on their shop.
     */
    public function updateMaintenance(
        Request $request,
        string $website,
        MoveWebsiteStatus $move,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $enabled = (bool) $validated['enabled'];
        $target = $enabled ? WebsiteStatus::Maintenance : WebsiteStatus::Active;

        if (! $record->canTransitionTo($target)) {
            throw ValidationException::withMessages(['status' => __('website.refused.status_does_not_allow')]);
        }

        $this->attempt(fn () => $move->handle(
            $record,
            $target,
            WebsiteStatusChangeSource::Account,
            new StatusChange(
                actorId: $this->person($request)->id,
                reason: $enabled ? 'maintenance_on' : 'maintenance_off',
            ),
            ['maintenance_message' => $enabled ? ($validated['message'] ?? null) : null],
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enabled ? __('website.flash.maintenance_on') : __('website.flash.maintenance_off'),
        ]);

        return to_route('websites.show', $record->public_id);
    }

    /**
     * Run one refusable step, reporting it where the person is looking.
     *
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step): void
    {
        try {
            $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['website' => __('website.refused.busy')]);
        }
    }

    /**
     * What the account's package allows, and how much of it is left (§8.1).
     *
     * @return array{allowed: bool, limit: int|null, used: int, remaining: int|null}
     */
    protected function entitlement(BusinessAccount $account): array
    {
        $used = Website::query()->forAccount($account)->open()->count();

        return [
            'allowed' => $account->canTransact()
                && $this->entitlements->allows($account, PackageFeature::DedicatedWebsite),
            'limit' => $this->entitlements->limit($account, PackageFeature::WebsiteLimit),
            'used' => $used,
            'remaining' => $this->entitlements->remaining($account, PackageFeature::WebsiteLimit, $used),
        ];
    }

    /**
     * What the wallet can put towards a charge right now.
     *
     * @return array{available: array<string, mixed>|null, currency: string}
     */
    protected function walletFigures(BusinessAccount $account): array
    {
        $wallet = $this->guard->walletFor($account);

        return [
            'available' => $wallet?->usableBalance()->jsonSerialize(),
            'currency' => $wallet === null ? Currency::base()->value : $wallet->currency_code,
        ];
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
