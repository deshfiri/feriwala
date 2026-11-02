<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\DepositGuard;
use App\Domain\Website\Actions\AllocatePackageWebsite;
use App\Domain\Website\Actions\MoveWebsiteStatus;
use App\Domain\Website\Actions\RenewWebsiteService;
use App\Domain\Website\Actions\RequestWebsite;
use App\Domain\Website\Actions\SettleWebsiteCharge;
use App\Domain\Website\Actions\SubmitWebsiteRequest;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Websites\Concerns\ResolvesOwnWebsite;
use App\Http\Requests\Mobile\Websites\RequestWebsiteRequest;
use App\Http\Requests\Mobile\Websites\UpdateMaintenanceRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Http\Resources\Mobile\Websites\ServiceHandle;
use App\Http\Resources\Mobile\Websites\WebsiteAllocationResource;
use App\Http\Resources\Mobile\Websites\WebsiteDetailResource;
use App\Http\Resources\Mobile\Websites\WebsiteResource;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The account's own dedicated websites (§16).
 *
 * Self-scoped through the signed-in person's own account, as on the web: a
 * website is found among this account's or not at all, so another partner's
 * identifier is a `404`. Nothing here decides what anything costs — charges were
 * priced when the website was asked for and are paid out of the wallet; the app
 * names which charge, never how much.
 */
class WebsiteController extends MobileController
{
    use ResolvesOwnWebsite;

    public function __construct(
        protected WebsiteOverview $overview,
        protected DepositGuard $guard,
    ) {}

    /**
     * List websites
     *
     * The account's websites, newest first (closed ones are gone), with the website
     * slots its package entitles it to (`allocations`, one card each, set up or still
     * waiting), what it may still ask for (`entitlement`) and whether this person may
     * ask (`can.create`).
     *
     * @response array{data: list<WebsiteResource>, allocations: list<WebsiteAllocationResource>, entitlement: array{allowed: bool, expired: bool, limit: int|null, used: int, remaining: int|null}, can: array{create: bool}}
     */
    public function index(Request $request, AllocatePackageWebsite $allocator, RequestWebsite $requestWebsite): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        // An account whose package became active before allocations existed is
        // recognised here. Idempotent, exactly as on the web list.
        $allocator->forAccount($account);

        return new JsonResponse([
            'data' => WebsiteResource::collection($this->overview->forAccount($account))->resolve($request),
            'allocations' => WebsiteAllocationResource::collection($this->overview->allocationsForAccount($account))->resolve($request),
            'entitlement' => $requestWebsite->allowance($account),
            'can' => ['create' => $request->user()?->can('create', Website::class) ?? false],
        ]);
    }

    /**
     * Website request quote
     *
     * What asking for a new website would involve: the account's allowance, the
     * wallet's usable balance, and the charges it would raise, priced by the same
     * call that raises them. Only a person who runs the business may ask (`403`).
     *
     * @response array{entitlement: array{allowed: bool, expired: bool, limit: int|null, used: int, remaining: int|null}, storefront_domain: string|null, wallet: array{available: MoneyResource|null, currency: string}, charges: array<string, MoneyResource>}
     */
    public function create(Request $request, RequestWebsite $quote): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', Website::class);

        return new JsonResponse([
            'entitlement' => $quote->allowance($account),
            'storefront_domain' => config('website.storefront_domain'),
            'wallet' => $this->walletFigures($account),
            'charges' => array_map(fn (Money $amount) => new MoneyResource($amount), $quote->quote($account)),
        ]);
    }

    /**
     * Request a website
     *
     * Asks for a dedicated website. It opens in setup pending with its charges due;
     * paying them moves it on. `403` when this person does not run the business;
     * `422` when the package allows no more websites or the address is taken or
     * reserved.
     */
    public function store(RequestWebsiteRequest $request, SubmitWebsiteRequest $submit): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', Website::class);

        $validated = $request->validated();

        $website = $submit->handle($account, $this->person($request), $validated['name'], $validated['subdomain']);

        return $this->detail($website)->response()->setStatusCode(201);
    }

    /**
     * Website detail
     *
     * Everything one website's page shows: branding, contact, the prices it was asked
     * for at, its lifecycle and connection, its charges, domain and hosting terms and
     * its status history. The registrar, the hosting provider and platform notes are
     * never included. `wallet.available` is what can be put towards a charge now.
     *
     * @response array{data: WebsiteDetailResource, wallet: array{available: MoneyResource|null, currency: string}, can: array{pay: bool, manage: bool}}
     */
    public function show(Request $request, string $website): JsonResponse
    {
        $record = $this->websiteFor($request, $website);
        $person = $this->person($request);

        return new JsonResponse([
            'data' => (new WebsiteDetailResource($this->overview->detail($record)))->resolve($request),
            'wallet' => $this->walletFigures($record->businessAccount),
            'can' => [
                'pay' => $person->can('pay', $record),
                'manage' => $person->can('manage', $record),
            ],
        ]);
    }

    /**
     * Pay a charge
     *
     * Pays one outstanding charge out of the wallet and returns the website. The
     * amount is the server's — the request names the charge only. `403` when this
     * person may not spend the account's money, `404` for a charge that is not this
     * website's, `422` when the wallet cannot cover it or the charge is not payable.
     */
    public function payCharge(Request $request, string $website, string $charge, SettleWebsiteCharge $settle): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('pay', $record);

        /** @var WebsiteCharge|null $due */
        $due = WebsiteCharge::query()
            ->where('website_id', $record->id)
            ->where('public_id', $charge)
            ->first();

        abort_if($due === null, 404);

        $this->attempt(fn () => $settle->handle($due, $this->person($request)));

        return $this->detail($record);
    }

    /**
     * Renew a domain
     *
     * Buys another term of the domain, paid from the wallet, and returns the website.
     * `domain` is the opaque `id` from the website's `domains`. `403` without the right
     * to pay, `404` for a domain that is not this website's, `422` when it cannot be
     * renewed.
     */
    public function renewDomain(Request $request, string $website, string $domain, RenewWebsiteService $renew): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('pay', $record);

        $registration = ServiceHandle::find('domain', $domain, $record->domains()->get());

        abort_if($registration === null, 404);

        $this->attempt(fn () => $renew->renewDomain(
            $registration,
            $this->person($request),
            (int) config('website.term_months', 12),
        ));

        return $this->detail($record);
    }

    /**
     * Renew hosting
     *
     * Buys another term of hosting, paid from the wallet, and returns the website.
     * `hosting` is the opaque `id` from the website's `hostings`. Errors as for a
     * domain renewal.
     */
    public function renewHosting(Request $request, string $website, string $hosting, RenewWebsiteService $renew): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('pay', $record);

        $term = ServiceHandle::find('hosting', $hosting, $record->hostings()->get());

        abort_if($term === null, 404);

        $this->attempt(fn () => $renew->renewHosting(
            $term,
            $this->person($request),
            (int) config('website.term_months', 12),
        ));

        return $this->detail($record);
    }

    /**
     * Set maintenance mode
     *
     * Puts the storefront into maintenance, or takes it back out — the one lifecycle
     * state a partner chooses for themselves. `403` without the right to manage the
     * website; `422` when its current status does not allow the move.
     */
    public function updateMaintenance(UpdateMaintenanceRequest $request, string $website, MoveWebsiteStatus $move): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validated();

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

        return $this->detail($record);
    }

    protected function detail(Website $record): WebsiteDetailResource
    {
        return new WebsiteDetailResource($this->overview->detail($record->refresh()));
    }

    /**
     * What the wallet can put towards a charge right now.
     *
     * @return array{available: MoneyResource|null, currency: string}
     */
    protected function walletFigures(BusinessAccount $account): array
    {
        $wallet = $this->guard->walletFor($account);

        return [
            'available' => MoneyResource::nullable($wallet?->usableBalance()),
            'currency' => $wallet === null ? Currency::base()->value : $wallet->currency_code,
        ];
    }
}
