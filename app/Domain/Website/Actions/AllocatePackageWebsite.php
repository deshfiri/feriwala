<?php

namespace App\Domain\Website\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turn a confirmed package purchase into the websites it entitles (§16.1, §16.2, D30 §9).
 *
 * Called by every path that makes a subscription active — account activation,
 * renewal, upgrade or downgrade, and an administrator assigning a package — and
 * lazily for the account's current subscription, so accounts that were already
 * trading before allocations existed are recognised too.
 *
 * **One allocation slot per permitted website.** The package's Websites limit,
 * read from the subscription's own terms, is how many: 0 is none, 2 is two.
 * A slot already written for a confirmed purchase is kept and still counts, so
 * an older subscription never loses a website it was given. An unlimited
 * package starts with one slot and gains another each time a website is set
 * up beyond them.
 *
 * **The package-based domain and hosting period starts at the subscription's
 * own `started_at`.** That is the moment the purchase was confirmed — after any
 * admin approval, never when the payment was first submitted — and it is
 * written once, here. Creating, editing, activating or launching the website,
 * or registering its domain, never moves it.
 *
 * - A first purchase opens a new period from its own confirmation.
 * - A renewal adds a new period for each slot and service, starting at the
 *   later of the renewed term's start and the end of that slot's period — so
 *   renewing early never restarts or shortens a domain or hosting term already
 *   paid for.
 * - An upgrade, downgrade or administrator reassignment keeps each slot's
 *   running period: no new domain or hosting was bought. A slot the old
 *   package did not have opens its own period from the confirmation.
 *
 * Idempotent. `(user_package_id, slot)` is unique, so a payment callback
 * delivered twice, or an approval clicked twice, finds the slots already there.
 */
class AllocatePackageWebsite
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Allocate every slot a subscription entitles, and return its first. Null
     * when the subscription allows no website, or is not (yet) an active,
     * started term.
     */
    public function handle(UserPackage $subscription): ?WebsiteAllocation
    {
        $terms = $this->termsOf($subscription);

        if ($terms === null) {
            return null;
        }

        $slots = $this->capacityOf($terms);

        if ($slots === 0) {
            // Nothing new — but a slot already written for this confirmed
            // purchase is kept, never taken back.
            return WebsiteAllocation::query()->where('user_package_id', $subscription->id)->orderBy('slot')->first();
        }

        $first = null;

        // An unlimited package starts with one slot; more open as websites
        // are set up (attachWebsite()).
        foreach (range(1, $slots ?? 1) as $slot) {
            $allocation = $this->allocateSlot($subscription, $terms, $slot);
            $first ??= $allocation;
        }

        return $first;
    }

    /**
     * How many websites a subscription allows: null for unlimited. Never fewer
     * than the slots already written for it — a website a confirmed purchase
     * was given is not taken back by reading its terms more strictly.
     */
    public function capacity(UserPackage $subscription): ?int
    {
        $terms = $subscription->terms();
        $limit = $terms === null ? 0 : $this->capacityOf($terms);

        if ($limit === null) {
            return null;
        }

        return max($limit, WebsiteAllocation::query()->where('user_package_id', $subscription->id)->count());
    }

    /**
     * Make sure the account's current subscription has its slots.
     *
     * For accounts whose subscription became active before allocations
     * existed. Writes nothing when they are already there.
     */
    public function forAccount(BusinessAccount $account): ?WebsiteAllocation
    {
        $subscription = $this->currentSubscription($account);

        return $subscription === null ? null : $this->handle($subscription);
    }

    /**
     * Give a website the partner has just set up a slot of `$subscription` —
     * the one the website itself is recorded against, so the two always agree.
     * Never a slot of another (older, renewed or replaced) subscription, whose
     * dates are not this website's, and never a slot whose website is open.
     *
     * In order: an empty slot; a slot whose website has since closed, which
     * frees it (recorded); and, for an unlimited package, a new slot. Null when
     * there is none — the caller must not keep the website then.
     */
    public function attachWebsite(Website $website, UserPackage $subscription): ?WebsiteAllocation
    {
        if ($website->business_account_id !== $subscription->business_account_id) {
            return null;
        }

        /** @var WebsiteAllocation|null $empty */
        $empty = WebsiteAllocation::query()
            ->where('user_package_id', $subscription->id)
            ->whereNull('website_id')
            ->orderBy('slot')
            ->lockForUpdate()
            ->first();

        if ($empty !== null) {
            $empty->forceFill(['website_id' => $website->id])->save();

            return $empty;
        }

        /** @var WebsiteAllocation|null $freed */
        $freed = WebsiteAllocation::query()
            ->where('user_package_id', $subscription->id)
            ->whereHas('website', fn ($query) => $query->where('status', WebsiteStatus::Closed->value))
            ->orderBy('slot')
            ->lockForUpdate()
            ->first();

        if ($freed !== null) {
            $closedWebsiteId = $freed->website_id;
            $freed->forceFill(['website_id' => $website->id])->save();

            $this->audit->handle(new AuditEntry(
                action: 'website.allocation_reused',
                actorType: 'system',
                auditableType: WebsiteAllocation::class,
                auditableId: $freed->id,
                before: ['website_id' => $closedWebsiteId],
                after: ['website_id' => $website->id, 'slot' => $freed->slot],
                accountId: $website->business_account_id,
                module: 'website',
            ));

            return $freed;
        }

        $terms = $this->termsOf($subscription);

        if ($terms === null || $this->capacityOf($terms) !== null) {
            return null;
        }

        $next = (int) WebsiteAllocation::query()->where('user_package_id', $subscription->id)->max('slot') + 1;
        $allocation = $this->allocateSlot($subscription, $terms, $next);

        if ($allocation->website_id === null) {
            $allocation->forceFill(['website_id' => $website->id])->save();
        }

        return $allocation;
    }

    /**
     * The subscription's terms when it entitles a website now; otherwise null.
     */
    protected function termsOf(UserPackage $subscription): ?SubscriptionTerms
    {
        if (! $subscription->status->entitles() || $subscription->started_at === null) {
            return null;
        }

        $terms = $subscription->terms();

        if ($terms === null || ! $terms->feature(PackageFeature::DedicatedWebsite)) {
            return null;
        }

        return $terms;
    }

    /**
     * The Websites limit as it was bought: an int, or null for unlimited.
     * Nothing without the dedicated-website feature.
     */
    protected function capacityOf(SubscriptionTerms $terms): ?int
    {
        if (! $terms->feature(PackageFeature::DedicatedWebsite)) {
            return 0;
        }

        $limit = $terms->feature(PackageFeature::WebsiteLimit);

        return $limit === null ? null : max(0, (int) $limit);
    }

    protected function currentSubscription(BusinessAccount $account): ?UserPackage
    {
        return $account->current_user_package_id === null
            ? null
            : UserPackage::query()->find($account->current_user_package_id);
    }

    protected function allocateSlot(UserPackage $subscription, SubscriptionTerms $terms, int $slot): WebsiteAllocation
    {
        $existing = WebsiteAllocation::query()
            ->where('user_package_id', $subscription->id)
            ->where('slot', $slot)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var CarbonImmutable $confirmedAt guarded by termsOf() */
        $confirmedAt = $subscription->started_at;

        $previous = $this->previousFor($subscription, $slot);
        $carriesPeriod = $previous !== null
            && $this->keepsRunningPeriod($subscription->source)
            && ! $previous->hasLapsed($confirmedAt);

        $domainMonths = $this->months($terms->feature(PackageFeature::DomainValidityMonths));
        $hostingMonths = $this->months($terms->feature(PackageFeature::HostingValidityMonths));

        if ($carriesPeriod) {
            $period = [
                'domain_starts_at' => $previous->domain_starts_at,
                'hosting_starts_at' => $previous->hosting_starts_at,
                'domain_validity_months' => $previous->domain_validity_months,
                'hosting_validity_months' => $previous->hosting_validity_months,
                'domain_expires_at' => $previous->domain_expires_at,
                'hosting_expires_at' => $previous->hosting_expires_at,
            ];
        } else {
            [$domainStart, $domainEnd] = $this->nextPeriod(
                $confirmedAt, $previous?->domain_starts_at, $previous?->domain_expires_at, $domainMonths, $previous !== null,
            );
            [$hostingStart, $hostingEnd] = $this->nextPeriod(
                $confirmedAt, $previous?->hosting_starts_at, $previous?->hosting_expires_at, $hostingMonths, $previous !== null,
            );

            $period = [
                'domain_starts_at' => $domainStart,
                'hosting_starts_at' => $hostingStart,
                'domain_validity_months' => $domainMonths,
                'hosting_validity_months' => $hostingMonths,
                'domain_expires_at' => $domainEnd,
                'hosting_expires_at' => $hostingEnd,
            ];
        }

        // When this purchase was confirmed, whatever its periods continue.
        $period['starts_at'] = $confirmedAt;

        try {
            // A savepoint inside whatever transaction activated the
            // subscription: PostgreSQL aborts the whole transaction on a failed
            // statement, so the duplicate the index is there to catch must not
            // take the activation with it.
            $allocation = $this->database->transaction(fn () => WebsiteAllocation::create([
                'business_account_id' => $subscription->business_account_id,
                'user_package_id' => $subscription->id,
                'slot' => $slot,
                'website_id' => $this->carriedWebsiteId($previous) ?? $this->unclaimedWebsiteId($subscription),
                'previous_allocation_id' => $previous?->id,
                'domain_included' => (bool) $terms->feature(PackageFeature::DomainIncluded),
                'hosting_included' => (bool) $terms->feature(PackageFeature::HostingIncluded),
                ...$period,
            ]));
        } catch (UniqueConstraintViolationException) {
            return WebsiteAllocation::query()
                ->where('user_package_id', $subscription->id)
                ->where('slot', $slot)
                ->firstOrFail();
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.allocated',
            // The purchase being confirmed did this, not a person.
            actorType: 'system',
            auditableType: WebsiteAllocation::class,
            auditableId: $allocation->id,
            after: [
                'user_package_id' => $subscription->id,
                'slot' => $slot,
                'source' => $subscription->source->value,
                'starts_at' => $allocation->starts_at->toIso8601String(),
                'domain_starts_at' => $allocation->domain_starts_at->toIso8601String(),
                'hosting_starts_at' => $allocation->hosting_starts_at->toIso8601String(),
                'domain_expires_at' => $allocation->domain_expires_at?->toIso8601String(),
                'hosting_expires_at' => $allocation->hosting_expires_at?->toIso8601String(),
                'carried_over' => $carriesPeriod,
            ],
            accountId: $subscription->business_account_id,
            module: 'website',
        ));

        return $allocation;
    }

    /**
     * The allocation a slot continues: the same slot of the term it renews or
     * replaces, otherwise the account's most recent allocation in that slot.
     */
    protected function previousFor(UserPackage $subscription, int $slot): ?WebsiteAllocation
    {
        if ($subscription->renews_user_package_id !== null) {
            $previous = WebsiteAllocation::query()
                ->where('user_package_id', $subscription->renews_user_package_id)
                ->where('slot', $slot)
                ->first();

            if ($previous !== null) {
                return $previous;
            }
        }

        return WebsiteAllocation::query()
            ->where('business_account_id', $subscription->business_account_id)
            ->where('user_package_id', '!=', $subscription->id)
            ->where('slot', $slot)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The website a slot carries into its next term — unless it has closed,
     * which leaves the slot free for the next website.
     */
    protected function carriedWebsiteId(?WebsiteAllocation $previous): ?int
    {
        if ($previous?->website_id === null) {
            return null;
        }

        $open = Website::query()->whereKey($previous->website_id)->open()->exists();

        return $open ? $previous->website_id : null;
    }

    /**
     * Whether this kind of term keeps the domain and hosting period already
     * running, rather than buying a new one.
     */
    protected function keepsRunningPeriod(SubscriptionSource $source): bool
    {
        return in_array($source, [
            SubscriptionSource::Upgrade,
            SubscriptionSource::Downgrade,
            SubscriptionSource::Manual,
            SubscriptionSource::Promotional,
        ], true);
    }

    /**
     * An open website the account set up before it had an allocation, so a
     * website that predates this feature is recognised rather than orphaned.
     * One already held by any of the account's slots is never taken.
     */
    protected function unclaimedWebsiteId(UserPackage $subscription): ?int
    {
        $accountId = $subscription->business_account_id;

        /** @var int|null $id */
        $id = Website::query()
            ->where('business_account_id', $accountId)
            ->open()
            ->whereNotIn('id', WebsiteAllocation::query()
                ->where('business_account_id', $accountId)
                ->whereNotNull('website_id')
                ->select('website_id'))
            ->orderBy('id')
            ->value('id');

        return $id;
    }

    protected function months(bool|int|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * Where a new period for one service begins and ends.
     *
     * From the confirmed purchase — unless the period it continues is still
     * running, in which case it starts where that one ends. A renewal paid
     * early, or a subscription term counted in days that ends before a domain
     * term counted in months, therefore neither restarts nor shortens what was
     * already paid for: the new months are added on after the old ones.
     *
     * A period that does not end simply carries on.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    protected function nextPeriod(
        CarbonImmutable $confirmedAt,
        ?CarbonImmutable $previousStart,
        ?CarbonImmutable $previousEnd,
        ?int $months,
        bool $continues,
    ): array {
        if ($continues && $previousStart !== null && $previousEnd === null) {
            return [$previousStart, null];
        }

        $start = $continues && $previousEnd !== null && $previousEnd->greaterThan($confirmedAt)
            ? $previousEnd
            : $confirmedAt;

        return [$start, $this->expiry($start, $months)];
    }

    /**
     * The end of a period of whole months, landing on the same day of the
     * month: 10 October 2026 plus 12 months is 10 October 2027. A start on the
     * 31st clamps to the end of a shorter month rather than spilling into the
     * next one.
     */
    protected function expiry(CarbonImmutable $start, ?int $months): ?CarbonImmutable
    {
        return $months === null ? null : $start->addMonthsNoOverflow($months);
    }
}
