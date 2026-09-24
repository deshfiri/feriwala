<?php

namespace App\Domain\Website\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Enums\WebsiteStatusReason;
use App\Domain\Website\Models\Website;
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
     * @return array<int, array<string, mixed>>
     */
    public function forAccount(BusinessAccount $account): array
    {
        return Website::query()
            ->forAccount($account)
            ->withCount(['charges as outstanding_charges_count' => fn (Builder $query) => $query
                ->where('status', WebsiteChargeStatus::Due->value)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Website $website) => $this->summary($website))
            ->all();
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
            ->when($status !== null && $status !== '', fn (Builder $query) => $query->where('status', $status))
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
            'tagline' => $website->tagline,
            'about' => $website->about,
            'theme' => $website->theme->value,
            'primary_color' => $website->primary_color,
            'secondary_color' => $website->secondary_color,
            // Addresses a browser can load, never storage paths.
            'logo_url' => $this->images->url($website->logo_path),
            'banner_url' => $this->images->url($website->banner_path),
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
