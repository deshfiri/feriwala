<?php

namespace App\Domain\Website\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turn a confirmed package purchase into the website it entitles (§16.1, §16.2).
 *
 * Called by every path that makes a subscription active — account activation,
 * renewal, upgrade or downgrade, and an administrator assigning a package — and
 * lazily for the account's current subscription, so accounts that were already
 * trading before allocations existed are recognised too.
 *
 * **The package-based domain and hosting period starts at the subscription's
 * own `started_at`.** That is the moment the purchase was confirmed — after any
 * admin approval, never when the payment was first submitted — and it is
 * written once, here. Creating, editing, activating or launching the website,
 * or registering its domain, never moves it.
 *
 * - A first purchase opens a new period from its own confirmation.
 * - A renewal adds a new period for each service, starting at the later of the
 *   renewed term's start and the end of the period it continues — so renewing
 *   early never restarts or shortens a domain or hosting term already paid
 *   for, even where the subscription is counted in days and the domain in
 *   months.
 * - An upgrade, downgrade or administrator reassignment keeps the period that
 *   is already running: no new domain or hosting was bought. It opens a new
 *   period only when there is none, or the old one has already run out.
 *
 * Idempotent. `user_package_id` is unique, so a payment callback delivered
 * twice, or an approval clicked twice, finds the allocation already there.
 */
class AllocatePackageWebsite
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Allocate the website a subscription entitles, or return the one it
     * already has. Null when the subscription does not include a website, or
     * is not (yet) an active, started term.
     */
    public function handle(UserPackage $subscription): ?WebsiteAllocation
    {
        if (! $subscription->status->entitles() || $subscription->started_at === null) {
            return null;
        }

        $terms = $subscription->terms();

        if ($terms === null || ! $terms->feature(PackageFeature::DedicatedWebsite)) {
            return null;
        }

        $existing = WebsiteAllocation::query()->where('user_package_id', $subscription->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $previous = $this->previousFor($subscription);
        $carriesPeriod = $previous !== null
            && $this->keepsRunningPeriod($subscription->source)
            && ! $previous->hasLapsed($subscription->started_at);

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
                $subscription->started_at, $previous?->domain_starts_at, $previous?->domain_expires_at, $domainMonths, $previous !== null,
            );
            [$hostingStart, $hostingEnd] = $this->nextPeriod(
                $subscription->started_at, $previous?->hosting_starts_at, $previous?->hosting_expires_at, $hostingMonths, $previous !== null,
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
        $period['starts_at'] = $subscription->started_at;

        try {
            // A savepoint inside whatever transaction activated the
            // subscription: PostgreSQL aborts the whole transaction on a failed
            // statement, so the duplicate the index is there to catch must not
            // take the activation with it.
            $allocation = $this->database->transaction(fn () => WebsiteAllocation::create([
                'business_account_id' => $subscription->business_account_id,
                'user_package_id' => $subscription->id,
                'website_id' => $previous->website_id ?? $this->unclaimedWebsiteId($subscription->business_account_id),
                'previous_allocation_id' => $previous?->id,
                'domain_included' => (bool) $terms->feature(PackageFeature::DomainIncluded),
                'hosting_included' => (bool) $terms->feature(PackageFeature::HostingIncluded),
                ...$period,
            ]));
        } catch (UniqueConstraintViolationException) {
            return WebsiteAllocation::query()->where('user_package_id', $subscription->id)->first();
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.allocated',
            // The purchase being confirmed did this, not a person.
            actorType: 'system',
            auditableType: WebsiteAllocation::class,
            auditableId: $allocation->id,
            after: [
                'user_package_id' => $subscription->id,
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
     * Make sure the account's current subscription has its allocation.
     *
     * For accounts whose subscription became active before allocations
     * existed. Writes nothing when one is already there.
     */
    public function forAccount(BusinessAccount $account): ?WebsiteAllocation
    {
        $subscription = $account->current_user_package_id === null
            ? null
            : UserPackage::query()->find($account->current_user_package_id);

        return $subscription === null ? null : $this->handle($subscription);
    }

    /**
     * Point the account's allocation that has no website yet at a website the
     * partner has just set up. Never moves a website already linked.
     */
    public function attachWebsite(Website $website): ?WebsiteAllocation
    {
        $allocation = WebsiteAllocation::query()
            ->where('business_account_id', $website->business_account_id)
            ->whereNull('website_id')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($allocation === null) {
            return null;
        }

        $allocation->forceFill(['website_id' => $website->id])->save();

        return $allocation;
    }

    /**
     * The allocation this subscription continues: the one written for the term
     * it renews or replaces, otherwise the account's most recent one.
     */
    protected function previousFor(UserPackage $subscription): ?WebsiteAllocation
    {
        if ($subscription->renews_user_package_id !== null) {
            $previous = WebsiteAllocation::query()
                ->where('user_package_id', $subscription->renews_user_package_id)
                ->first();

            if ($previous !== null) {
                return $previous;
            }
        }

        return WebsiteAllocation::query()
            ->where('business_account_id', $subscription->business_account_id)
            ->orderByDesc('id')
            ->first();
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
     */
    protected function unclaimedWebsiteId(int $accountId): ?int
    {
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
