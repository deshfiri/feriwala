<?php

namespace App\Domain\Website\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wallet\Queries\ResolveDepositRule;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAllocation;
use App\Domain\Website\Models\WebsiteCharge;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * A partner asks for a dedicated website (§16.2, P5-8, P5-10).
 *
 * Three questions decide whether they may, and all three are the server's:
 * the account is trading, its package includes a dedicated website, and it has
 * not used up the number of websites that package allows (§8.1). A browser that
 * has been told none of this still gets the same answers.
 *
 * What it will cost is **snapshotted onto the website and raised as charges**,
 * not looked up again later: a fee rule that changes next month must not
 * silently re-price a website somebody already asked for (§9). A package that
 * includes the domain or the hosting charges nothing for it — the feature is
 * what it buys, and charging for it again would be charging twice.
 *
 * Nothing is provisioned here. The website opens in **setup pending** with its
 * charges due; paying them is what moves it on (D9 — a person registers the
 * domain and stands the hosting up).
 *
 * The capacity check that counts runs **inside** the transaction, under a lock
 * on the account, and the website is created and given its allocation slot in
 * that same transaction — so two requests at once cannot both take the last
 * free website, and a website never exists without a slot (D30 §9).
 */
class RequestWebsite
{
    public function __construct(
        protected Entitlements $entitlements,
        protected FeeRuleResolver $fees,
        protected ResolveDepositRule $deposits,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected AllocatePackageWebsite $allocations,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(
        BusinessAccount $account,
        User $requestedBy,
        string $name,
        string $subdomain,
    ): Website {
        $subdomain = Str::lower(trim($subdomain));

        // A quick answer before any lock is taken; the one that counts is
        // given again under the account lock below.
        $this->assertEntitled($account);

        $currency = Currency::base();
        $now = CarbonImmutable::now();

        try {
            $website = $this->database->transaction(function () use (
                $account, $requestedBy, $name, $subdomain, $currency, $now
            ) {
                /*
                 * Two requests for the last free website must not both get
                 * it. The account row is locked first, and everything that
                 * decides — the subscription, the capacity, the free slot — is
                 * read again after the lock, from a fresh instance, so nothing
                 * read before another request committed is trusted (D30 §9).
                 */
                /** @var BusinessAccount $locked */
                $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

                $subscription = $this->assertEntitled($locked);
                $charges = $this->quote($locked, $now);
                $rule = $this->deposits->for($locked, $now);

                $website = Website::create([
                    'business_account_id' => $locked->id,
                    'user_package_id' => $subscription->id,
                    'name' => $name,
                    'slug' => $this->slugFor($name),
                    'subdomain' => $subdomain,
                    'status' => WebsiteStatus::SetupPending,
                    'currency_code' => $currency->value,
                    'setup_fee' => $charges[WebsiteChargeType::Setup->value],
                    'domain_fee' => $charges[WebsiteChargeType::Domain->value],
                    'hosting_fee' => $charges[WebsiteChargeType::Hosting->value],
                    'required_deposit' => $rule === null
                        ? Money::zero($currency)
                        : $rule->required_initial_deposit,
                    'minimum_balance' => $rule === null
                        ? Money::zero($currency)
                        : $rule->minimum_balance,
                    'created_by' => $requestedBy->id,
                ]);

                // The state it opens in, recorded like every other: a history
                // that starts at the first *change* cannot say where it began.
                $website->recordStatusChange(
                    null,
                    WebsiteStatus::SetupPending,
                    new StatusChange(actorId: $requestedBy->id),
                    ['source' => WebsiteStatusChangeSource::Account->value],
                );

                // The website takes a slot of the same subscription it was
                // recorded against. Its domain and hosting period was dated
                // from the confirmed purchase and stays exactly as it was
                // (§16.2). No slot, no website: the whole request rolls back.
                if ($this->allocations->attachWebsite($website, $subscription) === null) {
                    throw WebsiteRefused::limitReached($this->allocations->capacity($subscription) ?? 0);
                }

                foreach ($charges as $type => $amount) {
                    if (! $amount->isPositive()) {
                        continue;
                    }

                    WebsiteCharge::create([
                        'website_id' => $website->id,
                        'business_account_id' => $locked->id,
                        'type' => WebsiteChargeType::from($type),
                        'status' => WebsiteChargeStatus::Due,
                        'currency_code' => $currency->value,
                        'amount' => $amount,
                        'due_at' => $now,
                        'created_by' => $requestedBy->id,
                    ]);
                }

                return $website;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people asked for the same address at once, or somebody else
            // already holds it. The index settled it; this says so.
            throw WebsiteRefused::subdomainTaken();
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.created',
            actorId: $requestedBy->id,
            auditableType: Website::class,
            auditableId: $website->id,
            after: [
                'name' => $website->name,
                'subdomain' => $website->subdomain,
                'status' => $website->status->value,
            ],
            accountId: $account->id,
            module: 'website',
        ));

        StaffQueue::WebsiteRequest->announce($website);

        return $website;
    }

    /**
     * What a website would cost this account today (§16.2, §9).
     *
     * The same figures the request screen shows and the request itself writes
     * down, because they are the same call: a quote worked out one way and a
     * charge raised another is how somebody ends up paying a price they were
     * never shown.
     *
     * @return array<string, Money> keyed by {@see WebsiteChargeType} value
     */
    public function quote(BusinessAccount $account, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $subscription = $this->entitlements->activePackage($account);
        $currency = Currency::base();

        return [
            WebsiteChargeType::Setup->value => $this->fees->websiteCharge(
                WebsiteChargeType::Setup->feeType(), $subscription?->package, $currency, $at,
            ),

            // A package that includes the domain or the hosting has already been
            // paid for it (§8.1).
            WebsiteChargeType::Domain->value => $this->entitlements->allows($account, PackageFeature::DomainIncluded)
                ? Money::zero($currency)
                : $this->fees->websiteCharge(WebsiteChargeType::Domain->feeType(), $subscription?->package, $currency, $at),

            WebsiteChargeType::Hosting->value => $this->entitlements->allows($account, PackageFeature::HostingIncluded)
                ? Money::zero($currency)
                : $this->fees->websiteCharge(WebsiteChargeType::Hosting->feeType(), $subscription?->package, $currency, $at),
        ];
    }

    /**
     * What the account may still ask for (§8.1, §16.2, D30 §9).
     *
     * The one answer the screens show and {@see handle()} enforces, so a
     * button that is hidden and a request that is refused can never disagree.
     *
     * - The cap is the package's Websites limit as the subscription bought it
     *   — the same number of allocation slots {@see AllocatePackageWebsite}
     *   opens. 0 allows none. A slot already written for the purchase still
     *   counts, so an older subscription keeps the website it was given.
     * - Closed websites do not count: a partner who closed one and asked for
     *   another has not used two of anything.
     * - Once every slot's domain or hosting period has run out, nothing new is
     *   allowed until a renewal opens the next period.
     *
     * @return array{allowed: bool, expired: bool, limit: int|null, used: int, remaining: int|null}
     */
    public function allowance(BusinessAccount $account): array
    {
        $evaluated = $this->evaluate($account);
        unset($evaluated['subscription']);

        return $evaluated;
    }

    /**
     * {@see allowance()}, with the subscription it was worked out from — the
     * one a new website is recorded against and takes its slot from, so the
     * two can never disagree.
     *
     * @return array{allowed: bool, expired: bool, limit: int|null, used: int, remaining: int|null, subscription: UserPackage|null}
     */
    protected function evaluate(BusinessAccount $account): array
    {
        $subscription = $this->entitlements->activePackage($account);
        $limit = $subscription === null ? 0 : $this->allocations->capacity($subscription);

        $allowed = $subscription !== null
            && $account->canTransact()
            && $this->entitlements->allows($account, PackageFeature::DedicatedWebsite)
            && $limit !== 0;

        $used = Website::query()->forAccount($account)->open()->count();
        $remaining = $limit === null ? null : max(0, $limit - $used);

        // Make sure this subscription's slots exist, then ask about them.
        $expired = $allowed
            && $this->allocations->handle($subscription) !== null
            && $this->everySlotHasLapsed($subscription->id);

        if ($expired) {
            $remaining = 0;
        }

        return [
            'allowed' => $allowed,
            'expired' => $expired,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'subscription' => $subscription,
        ];
    }

    /**
     * Whether every slot of a purchase has run out of domain or hosting time.
     */
    protected function everySlotHasLapsed(int $subscriptionId): bool
    {
        $slots = WebsiteAllocation::query()->where('user_package_id', $subscriptionId)->get();

        return $slots->isNotEmpty()
            && $slots->every(fn (WebsiteAllocation $slot) => $slot->hasLapsed());
    }

    /**
     * Refuse unless the account may have one more website, and say which
     * subscription it would be.
     *
     * @throws WebsiteRefused
     */
    protected function assertEntitled(BusinessAccount $account): UserPackage
    {
        $allowance = $this->evaluate($account);

        if (! $allowance['allowed'] || $allowance['subscription'] === null) {
            throw WebsiteRefused::notEntitled();
        }

        if ($allowance['expired']) {
            throw WebsiteRefused::entitlementExpired();
        }

        if ($allowance['remaining'] !== null && $allowance['remaining'] < 1) {
            throw WebsiteRefused::limitReached($allowance['limit'] ?? 0);
        }

        return $allowance['subscription'];
    }

    /**
     * A slug nobody else holds.
     *
     * Two partners may reasonably name a shop the same thing, and the slug is
     * only an identifier — so it gains a suffix rather than refusing the name.
     */
    protected function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'website';
        $slug = $base;

        while (Website::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
