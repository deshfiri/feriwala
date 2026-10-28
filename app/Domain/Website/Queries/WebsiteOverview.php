<?php

namespace App\Domain\Website\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Website\Enums\PackageWebsiteStatus;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Enums\WebsiteStatusReason;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAllocation;
use App\Domain\Website\Models\WebsiteCharge;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Domain\Website\Models\WebsiteStatusChange;
use App\Domain\Website\WebsiteImageStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * What a website looks like on a screen, to each audience (§16.2, §16.3, §31.3).
 *
 * One place, so the partner's page and the platform's page cannot drift into
 * showing different things — and so the line between them is written down once
 * rather than remembered at every call site. `$forStaff` is what moves that
 * line, and it moves it in exactly three places:
 *
 *   - **internal notes** on status changes, written about the partner for the
 *     platform (§7.2's reasoning, applied to §16.4);
 *   - **who** made a change and why, which is platform decision metadata;
 *   - **the registrar and the hosting provider**, which are Feriwala's own
 *     suppliers. A partner is told when their hosting runs out, never where
 *     their shop is hosted.
 *
 * Everything else is the same figures on both screens, so the two sides of a
 * support call are not looking at different websites.
 */
class WebsiteOverview
{
    public function __construct(
        protected WebsiteImageStore $images,
    ) {}

    /**
     * One account's own websites, newest first.
     *
     * Each row carries the domain and hosting staff have recorded against it
     * (§16.2, P5-11) — null until they have — so the partner sees what was
     * registered and when it runs out without opening every website. The
     * registrar and the provider stay off this list, as they stay off the
     * partner's website page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forAccount(BusinessAccount $account): array
    {
        $now = CarbonImmutable::now();

        return Website::query()
            ->forAccount($account)
            ->with(['domains', 'hostings'])
            ->withCount(['charges as outstanding_charges_count' => fn (Builder $query) => $query
                ->where('status', WebsiteChargeStatus::Due->value)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Website $website) => [
                ...$this->summary($website),
                'domain_record' => $this->domainRecord($this->currentDomain($website), $now),
                'hosting_record' => $this->hostingRecord($this->currentHosting($website), $now),
            ])
            ->all();
    }

    /**
     * The domain a website answers on now: the newest one still running,
     * otherwise the newest of any — an expired domain is still worth showing
     * as expired rather than as nothing.
     */
    protected function currentDomain(Website $website): ?WebsiteDomain
    {
        $domains = $website->domains->sortByDesc('id');

        return $domains->first(fn (WebsiteDomain $domain) => $domain->status->isRunning()) ?? $domains->first();
    }

    /**
     * The hosting a website runs on now, chosen the same way as its domain.
     */
    protected function currentHosting(Website $website): ?WebsiteHosting
    {
        $hostings = $website->hostings->sortByDesc('id');

        return $hostings->first(fn (WebsiteHosting $hosting) => $hosting->status->isRunning()) ?? $hostings->first();
    }

    /**
     * @return array{domain: string, status: string, status_label: string, registered_at: string|null, expires_at: string|null, days_remaining: int|null}|null
     */
    protected function domainRecord(?WebsiteDomain $domain, CarbonImmutable $now): ?array
    {
        return $domain === null ? null : [
            'domain' => $domain->domain,
            'status' => $domain->status->value,
            'status_label' => __('website.service_statuses.'.$domain->status->value),
            'registered_at' => $domain->registered_at?->toIso8601String(),
            'expires_at' => $domain->expires_at?->toIso8601String(),
            'days_remaining' => $domain->daysRemaining($now),
        ];
    }

    /**
     * @return array{plan: string, status: string, status_label: string, started_at: string|null, expires_at: string|null, days_remaining: int|null}|null
     */
    protected function hostingRecord(?WebsiteHosting $hosting, CarbonImmutable $now): ?array
    {
        return $hosting === null ? null : [
            'plan' => $hosting->plan,
            'status' => $hosting->status->value,
            'status_label' => __('website.service_statuses.'.$hosting->status->value),
            'started_at' => $hosting->started_at?->toIso8601String(),
            'expires_at' => $hosting->expires_at?->toIso8601String(),
            'days_remaining' => $hosting->daysRemaining($now),
        ];
    }

    /**
     * The websites an account's package purchases entitle it to, one card each
     * (§16.1, §16.2).
     *
     * One card per allocation slot — its most recent allocation, since a
     * renewal or a package change writes a new one for the same slot — so a
     * package that allows two websites shows two cards, set up or still
     * waiting (D30 §9). Earlier terms are history, not separate websites.
     *
     * The dates are the allocation's: the package-based period from the
     * confirmed purchase. They are labelled package-based until the domain or
     * hosting has actually been registered or stood up.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allocationsForAccount(BusinessAccount $account): array
    {
        $now = CarbonImmutable::now();

        $allocations = WebsiteAllocation::query()
            ->where('business_account_id', $account->id)
            ->with(['website.domains', 'website.hostings', 'userPackage', 'businessAccount'])
            ->orderByDesc('id')
            ->get();

        $seen = [];
        $cards = [];

        foreach ($allocations as $allocation) {
            $slot = 'slot:'.$allocation->slot;
            $website = $allocation->website_id === null ? null : 'website:'.$allocation->website_id;

            // A website already on a newer card is not shown again, and nor is
            // an older term of a slot already shown.
            if (isset($seen[$slot]) || ($website !== null && isset($seen[$website]))) {
                continue;
            }

            $seen[$slot] = true;

            if ($website !== null) {
                $seen[$website] = true;
            }

            $cards[] = $this->allocationCard($allocation, $now);
        }

        // Slot order reads as "website 1, website 2" on the page.
        usort($cards, fn (array $a, array $b) => $a['slot'] <=> $b['slot']);

        return $cards;
    }

    /**
     * One allocated website as the partner reads it.
     *
     * @return array<string, mixed>
     */
    public function allocationCard(WebsiteAllocation $allocation, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $website = $allocation->website;
        $status = PackageWebsiteStatus::for($website, $allocation, $now);

        $registeredDomain = $website?->domains
            ->first(fn (WebsiteDomain $domain) => $domain->status->isRunning());
        $runningHosting = $website?->hostings
            ->first(fn (WebsiteHosting $hosting) => $hosting->status->isRunning());

        return [
            'id' => $allocation->public_id,
            'slot' => $allocation->slot,

            // The website's own name once it has one, otherwise the business
            // the package was bought for.
            'name' => $website->name ?? $allocation->businessAccount->name,
            'website' => $website === null ? null : [
                'id' => $website->public_id,
                'host' => $website->host(),
                'url' => $website->origin(),
            ],
            'domain_name' => $registeredDomain->domain ?? $website?->domain,
            'partner_name' => $allocation->businessAccount->name,
            'package_name' => $allocation->userPackage->terms()?->name,

            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),

            /*
             * The package's period and the provider's record are kept apart.
             * `starts_at`/`expires_at` are what the package entitles; `provider`
             * is what was actually registered or stood up, if anything yet —
             * never one presented as the other.
             */
            'domain' => [
                'included' => $allocation->domain_included,
                'starts_at' => $allocation->domain_starts_at->toIso8601String(),
                'expires_at' => $allocation->domain_expires_at?->toIso8601String(),
                'days_remaining' => $allocation->domainDaysRemaining($now),
                'package_based' => $registeredDomain === null,
                'provider' => $registeredDomain === null ? null : [
                    'started_at' => $registeredDomain->registered_at?->toIso8601String(),
                    'expires_at' => $registeredDomain->expires_at?->toIso8601String(),
                ],
            ],
            'hosting' => [
                'included' => $allocation->hosting_included,
                'starts_at' => $allocation->hosting_starts_at->toIso8601String(),
                'expires_at' => $allocation->hosting_expires_at?->toIso8601String(),
                'days_remaining' => $allocation->hostingDaysRemaining($now),
                'package_based' => $runningHosting === null,
                'provider' => $runningHosting === null ? null : [
                    'plan' => $runningHosting->plan,
                    'started_at' => $runningHosting->started_at?->toIso8601String(),
                    'expires_at' => $runningHosting->expires_at?->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * The platform's list, filtered and paged.
     *
     * @param  array{search?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Website>
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        return Website::query()
            ->with('businessAccount:id,public_id,name')
            ->withCount(['charges as outstanding_charges_count' => fn (Builder $query) => $query
                ->where('status', WebsiteChargeStatus::Due->value)])
            // Closed websites are the "deleted" ones: out of the list unless
            // somebody asks for them by status.
            ->when(
                $status !== null && $status !== '',
                fn (Builder $query) => $query->where('status', $status),
                fn (Builder $query) => $query->open(),
            )
            ->when($search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('subdomain', 'ilike', '%'.$search.'%')
                    ->orWhere('domain', 'ilike', '%'.$search.'%')))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * A website as a row in a list.
     *
     * @return array<string, mixed>
     */
    public function summary(Website $website): array
    {
        return [
            'id' => $website->public_id,
            'name' => $website->name,
            'host' => $website->host(),
            'status' => $website->status->value,
            'status_label' => __('website.statuses.'.$website->status->value),
            'is_live' => $website->status->isLive(),
            'connection_health' => $website->connection_health->value,
            'last_synced_at' => $website->last_synced_at?->toIso8601String(),
            'outstanding_charges' => (int) ($website->getAttribute('outstanding_charges_count') ?? 0),
            // Only where the caller loaded it: a partner's own list has no use
            // for the name of the account they are already inside.
            'account' => $website->relationLoaded('businessAccount')
                ? ['id' => $website->businessAccount->public_id, 'name' => $website->businessAccount->name]
                : null,
            'created_at' => $website->created_at->toIso8601String(),
        ];
    }

    /**
     * Everything one website's page shows.
     *
     * @return array<string, mixed>
     */
    public function detail(Website $website, bool $forStaff = false): array
    {
        $website->loadMissing(['domains', 'hostings', 'charges', 'statusHistory.changedBy', 'businessAccount']);
        $now = CarbonImmutable::now();

        return [
            ...$this->summary($website),

            'subdomain' => $website->subdomain,
            'domain' => $website->domain,
            'requested_address' => $website->requested_address,
            'tagline' => $website->tagline,
            'about' => $website->about,
            'theme' => $website->theme->value,
            'primary_color' => $website->primary_color,
            'secondary_color' => $website->secondary_color,
            // Addresses a browser can load, never storage paths.
            'logo_url' => $this->images->url($website->logo_path),
            'banner_url' => $this->images->url($website->banner_path),
            'banner_link_url' => $website->banner_link_url,
            'contact' => [
                'email' => $website->contact_email,
                'phone' => $website->contact_phone,
                'address' => $website->contact_address,
            ],

            'charges_summary' => [
                'setup' => $website->setup_fee->jsonSerialize(),
                'domain' => $website->domain_fee->jsonSerialize(),
                'hosting' => $website->hosting_fee->jsonSerialize(),
                'required_deposit' => $website->required_deposit->jsonSerialize(),
                'minimum_balance' => $website->minimum_balance->jsonSerialize(),
            ],

            'lifecycle' => [
                'activated_at' => $website->activated_at?->toIso8601String(),
                'expires_at' => $website->expires_at?->toIso8601String(),
                'grace_ends_at' => $website->grace_ends_at?->toIso8601String(),
                'suspended_at' => $website->suspended_at?->toIso8601String(),

                // The reason a partner's shop is down is theirs to read: §16.4
                // is a state they have to act on, not a note written about them.
                'suspension_reason' => $website->suspension_reason,
                'maintenance_message' => $website->maintenance_message,
            ],

            'connection' => [
                'health' => $website->connection_health->value,
                'health_label' => __('website.health.'.$website->connection_health->value),
                'api_connected_at' => $website->api_connected_at?->toIso8601String(),
                'webhook_connected_at' => $website->webhook_connected_at?->toIso8601String(),
                'last_synced_at' => $website->last_synced_at?->toIso8601String(),
            ],

            'charges' => $website->charges
                ->sortByDesc('id')
                ->map(fn (WebsiteCharge $charge) => [
                    'id' => $charge->public_id,
                    'type' => $charge->type->value,
                    'type_label' => __('website.charge_types.'.$charge->type->value),
                    'status' => $charge->status->value,
                    'status_label' => __('website.charge_statuses.'.$charge->status->value),
                    'amount' => $charge->amount->jsonSerialize(),
                    'due_at' => $charge->due_at->toIso8601String(),
                    'paid_at' => $charge->paid_at?->toIso8601String(),
                    'period_start' => $charge->period_start?->toIso8601String(),
                    'period_end' => $charge->period_end?->toIso8601String(),
                ])
                ->values()
                ->all(),

            'domains' => $website->domains
                ->map(fn (WebsiteDomain $domain) => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'status' => $domain->status->value,
                    'status_label' => __('website.service_statuses.'.$domain->status->value),
                    'registered_at' => $domain->registered_at?->toIso8601String(),
                    'expires_at' => $domain->expires_at?->toIso8601String(),
                    'days_remaining' => $domain->daysRemaining($now),
                    'auto_renew' => $domain->auto_renew,
                    'fee' => $domain->fee->jsonSerialize(),

                    // Feriwala's supplier, not the partner's business.
                    'registrar' => $forStaff ? $domain->registrar : null,

                    // Whether the shared storefront may answer on it (D30 §3).
                    'routing_status' => $domain->routing_status->value,
                    'routing_status_label' => $domain->routing_status->label(),
                    'serves' => $domain->serves(),
                    'verified_at' => $domain->verified_at?->toIso8601String(),

                    // How to prove it, and what the last check found — for staff.
                    'verification' => $forStaff && $domain->verification_token !== null ? [
                        'record_name' => $domain->verificationRecordName(),
                        'record_value' => $domain->verification_token,
                        'checked_at' => $domain->verification_checked_at?->toIso8601String(),
                        'error' => $domain->verification_error,
                    ] : null,
                    'tls_authorized_at' => $forStaff ? $domain->tls_authorized_at?->toIso8601String() : null,
                ])
                ->values()
                ->all(),

            'hostings' => $website->hostings
                ->map(fn (WebsiteHosting $hosting) => [
                    'id' => $hosting->id,
                    'plan' => $hosting->plan,
                    'status' => $hosting->status->value,
                    'status_label' => __('website.service_statuses.'.$hosting->status->value),
                    'started_at' => $hosting->started_at?->toIso8601String(),
                    'expires_at' => $hosting->expires_at?->toIso8601String(),
                    'days_remaining' => $hosting->daysRemaining($now),
                    'auto_renew' => $hosting->auto_renew,
                    'fee' => $hosting->fee->jsonSerialize(),
                    'provider' => $forStaff ? $hosting->provider : null,
                ])
                ->values()
                ->all(),

            'history' => $website->statusHistory
                ->map(fn (WebsiteStatusChange $change) => [
                    'id' => $change->id,
                    'previous_status' => $change->previous_status?->value,
                    'new_status' => $change->new_status->value,
                    'new_status_label' => __('website.statuses.'.$change->new_status->value),
                    'changed_at' => $change->changed_at->toIso8601String(),

                    // A move the platform made itself is told in the reader's
                    // language; what a person wrote is shown as they wrote it.
                    'note' => $this->reasonFor($change)?->note() ?? $change->public_note,

                    // Who decided, why, and what they wrote about it stays on
                    // the platform (§7.2, §16.4).
                    'source' => $forStaff ? $change->source->value : null,
                    'reason' => $forStaff ? ($this->reasonFor($change)?->label() ?? $change->reason) : null,
                    'internal_note' => $forStaff ? $change->internal_note : null,
                    'changed_by' => $forStaff ? $change->changedBy?->name : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One of this account's websites, or null when the identifier is not theirs.
     *
     * Self-scoping is a query concern (§31.3): the account is part of the
     * lookup, so another account's identifier is a 404 rather than a refusal
     * that confirms the website exists.
     */
    /**
     * The platform's own reason for a move, when it was the platform that made it.
     */
    protected function reasonFor(WebsiteStatusChange $change): ?WebsiteStatusReason
    {
        if ($change->source === WebsiteStatusChangeSource::Staff || $change->reason === null) {
            return null;
        }

        return WebsiteStatusReason::tryFrom($change->reason);
    }

    public function findForAccount(BusinessAccount $account, string $publicId): ?Website
    {
        /** @var Website|null $website */
        $website = Website::query()
            ->forAccount($account)
            ->where('public_id', $publicId)
            ->first();

        return $website;
    }
}
