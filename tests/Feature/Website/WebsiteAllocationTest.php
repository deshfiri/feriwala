<?php

use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Actions\ActivatePackageChange;
use App\Domain\Package\Actions\ActivateRenewal;
use App\Domain\Package\Actions\AssignPackage;
use App\Domain\Package\Actions\OpenPackageChange;
use App\Domain\Package\Actions\OpenRenewal;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Website\Actions\AllocatePackageWebsite;
use App\Domain\Website\Actions\ProvisionWebsiteService;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAllocation;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Package-based website allocation (§16.1, §16.2).
 *
 * A confirmed purchase of a package that includes a website allocates one, and
 * the domain and hosting period starts at that confirmation — never when the
 * website is created, configured, activated or launched.
 */

/**
 * A package; `$features` keyed by PackageFeature value.
 *
 * @param  array<string, string|null>  $features
 */
function websiteAllocationPackage(array $features, string $fee = '5000.00'): Package
{
    $package = Package::create([
        'slug' => 'alloc-'.Str::lower(Str::random(8)),
        'name' => 'Growth '.$fee,
        'fee' => Money::fromDecimal($fee, Currency::BDT),
        'renewal_fee' => Money::fromDecimal($fee, Currency::BDT),
        'renewal_frequency' => 'yearly',
        'validity_days' => 365,
        'grace_period_days' => 14,
        'required_deposit' => Money::zero(Currency::BDT),
        'minimum_balance' => Money::zero(Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    foreach ($features as $feature => $value) {
        $package->features()->create(['feature' => $feature, 'value' => $value]);
    }

    return $package->refresh();
}

/**
 * A package that includes one website, its domain and its hosting.
 *
 * @param  array<string, string|null>  $extra
 */
function websiteAllocationWebsitePackage(array $extra = [], string $fee = '5000.00'): Package
{
    return websiteAllocationPackage([
        PackageFeature::DedicatedWebsite->value => '1',
        PackageFeature::DomainIncluded->value => '1',
        PackageFeature::HostingIncluded->value => '1',
        PackageFeature::WebsiteLimit->value => '1',
        ...$extra,
    ], $fee);
}

/**
 * An account that has paid for `$package` and is waiting for approval — the
 * subscription sits in pending payment until the purchase is confirmed.
 */
function websiteAllocationPurchase(Package $package): BusinessAccount
{
    $account = testAccountReadyForActivation();

    UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::PendingPayment,
        'source' => SubscriptionSource::Purchase,
        'paid_fee' => $package->fee,
        'currency_code' => 'BDT',
        'terms' => SubscriptionTerms::capture($package->load(['features', 'charges']))->toArray(),
        'terms_captured_at' => now(),
    ]);

    return $account->refresh();
}

/**
 * Confirm the purchase through the real activation, and return the account.
 */
function websiteAllocationConfirm(BusinessAccount $account): BusinessAccount
{
    return app(ActivateAccount::class)->handle($account, approvedBy: null)->refresh();
}

/**
 * Move a website to Active along its own status map, as the platform would.
 */
function websiteAllocationGoLive(Website $website): Website
{
    foreach ([WebsiteStatus::Development, WebsiteStatus::Active] as $status) {
        $website->transitionTo($status)->save();
    }

    return $website->refresh();
}

describe('a confirmed package purchase', function () {
    it('allocates a website, with domain and hosting counted from the confirmation', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));

        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));

        $allocation = WebsiteAllocation::query()->sole();
        $subscription = UserPackage::query()->findOrFail($account->current_user_package_id);

        expect($allocation->business_account_id)->toBe($account->id)
            ->and($allocation->user_package_id)->toBe($subscription->id)
            ->and($allocation->website_id)->toBeNull()
            ->and($allocation->starts_at->equalTo($subscription->started_at))->toBeTrue()
            ->and($allocation->starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->domain_starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->hosting_starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->domain_expires_at?->toDateTimeString())->toBe('2027-10-10 09:30:00')
            ->and($allocation->hosting_expires_at?->toDateTimeString())->toBe('2027-10-10 09:30:00')
            ->and($allocation->domain_included)->toBeTrue()
            ->and($allocation->hosting_included)->toBeTrue();
    });

    it('starts the period when an approval confirms the purchase, not when it was paid', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 11:00:00'));
        $account = websiteAllocationPurchase(websiteAllocationWebsitePackage());

        // Three days in the approval queue.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        websiteAllocationConfirm($account);

        $allocation = WebsiteAllocation::query()->sole();

        expect($allocation->starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->domain_expires_at?->toDateTimeString())->toBe('2027-10-10 09:30:00');
    });

    it('uses each service’s own validity period from the package', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));

        websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage([
            PackageFeature::DomainValidityMonths->value => '24',
            PackageFeature::HostingValidityMonths->value => '6',
        ])));

        $allocation = WebsiteAllocation::query()->sole();

        expect($allocation->domain_validity_months)->toBe(24)
            ->and($allocation->hosting_validity_months)->toBe(6)
            ->and($allocation->domain_expires_at?->toDateString())->toBe('2028-10-10')
            ->and($allocation->hosting_expires_at?->toDateString())->toBe('2027-04-10');
    });

    it('allocates nothing, and allows no website, for a package without one', function () {
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationPackage([
            PackageFeature::DedicatedWebsite->value => '0',
        ])));

        expect(WebsiteAllocation::query()->count())->toBe(0);

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('allocations', 0)
                ->where('entitlement.allowed', false));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders'])
            ->assertSessionHasErrors();

        expect(Website::query()->count())->toBe(0);
    });
});

describe('the website set up against an allocation', function () {
    it('keeps the purchase dates however late it is set up, edited, activated or registered', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));

        // Set up six weeks later.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));
        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders'])
            ->assertRedirect();

        $website = Website::query()->sole();
        $allocation = WebsiteAllocation::query()->sole();

        expect($allocation->website_id)->toBe($website->id);

        // Edited, then launched a month after that.
        $this->actingAs($account->owner)
            ->put(route('websites.settings.update', $website->public_id), [
                'name' => 'Karim Traders Online',
                'theme' => WebsiteTheme::Modern->value,
                'primary_color' => '#0b0b0b',
                'secondary_color' => '#ff6600',
            ])
            ->assertRedirect();

        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
        $website = websiteAllocationGoLive($website);

        // The domain and hosting are arranged by hand later still.
        $this->travelTo(CarbonImmutable::parse('2027-01-05 12:00:00'));
        $provision = app(ProvisionWebsiteService::class);
        $domain = $provision->registerDomain($website, $account->owner, 'karimtraders.com', termMonths: 12);
        $hosting = $provision->startHosting($website, $account->owner, 'Standard', termMonths: 12);

        $allocation->refresh();

        // The package's period is untouched by everything above.
        expect($allocation->starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->domain_starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->hosting_starts_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
            ->and($allocation->domain_expires_at?->toDateTimeString())->toBe('2027-10-10 09:30:00')
            ->and($allocation->hosting_expires_at?->toDateTimeString())->toBe('2027-10-10 09:30:00')
            // And the provider records hold what was actually registered —
            // never the package's date written in as though it were theirs.
            ->and($domain->registered_at?->toDateString())->toBe('2027-01-05')
            ->and($domain->expires_at?->toDateString())->toBe('2028-01-05')
            ->and($hosting->expires_at?->toDateString())->toBe('2028-01-05');

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allocations', 1)
                ->where('allocations.0.name', 'Karim Traders Online')
                ->where('allocations.0.domain_name', 'karimtraders.com')
                ->where('allocations.0.partner_name', $account->name)
                ->where('allocations.0.status', 'active')
                ->where('allocations.0.domain.package_based', false)
                ->where('allocations.0.hosting.package_based', false)
                // The package period and the provider record, side by side
                // and never one presented as the other.
                ->where('allocations.0.domain.expires_at', CarbonImmutable::parse('2027-10-10 09:30:00')->toIso8601String())
                ->where('allocations.0.domain.days_remaining', 278)
                ->where('allocations.0.domain.provider.expires_at', $domain->expires_at?->toIso8601String())
                ->where('allocations.0.hosting.provider.expires_at', $hosting->expires_at?->toIso8601String()));
    });

    it('shows an allocation still waiting for its website as pending setup, labelled package-based', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00'));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allocations', 1)
                ->where('allocations.0.website', null)
                ->where('allocations.0.name', $account->name)
                ->where('allocations.0.status', 'pending_setup')
                ->where('allocations.0.domain.package_based', true)
                ->where('allocations.0.domain.days_remaining', 355)
                ->where('allocations.0.domain.expires_at', CarbonImmutable::parse('2027-10-10 09:30:00')->toIso8601String()));
    });

    it('reads as expired once the package-based period has run out', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage([
            PackageFeature::HostingValidityMonths->value => '1',
        ])));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders']);
        websiteAllocationGoLive(Website::query()->sole());

        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:30:00'));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('allocations.0.status', 'expired')
                ->where('allocations.0.hosting.days_remaining', -5));
    });

    it('reads as expired, not pending setup, when the period ran out before a website was set up', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage([
            PackageFeature::DomainValidityMonths->value => '1',
            PackageFeature::HostingValidityMonths->value => '1',
        ])));

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:30:00'));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('allocations.0.website', null)
                ->where('allocations.0.status', 'expired')
                ->where('allocations.0.domain.days_remaining', -10));
    });

    it('reads a suspended website as suspended even after its period has run out', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage([
            PackageFeature::HostingValidityMonths->value => '1',
        ])));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders']);
        $website = websiteAllocationGoLive(Website::query()->sole());
        $website->transitionTo(WebsiteStatus::Suspended)->save();

        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:30:00'));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('allocations.0.status', 'suspended'));
    });
});

describe('repeated confirmation events', function () {
    it('allocates once however many times the confirmation arrives', function () {
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $subscription = UserPackage::query()->findOrFail($account->current_user_package_id);
        $first = WebsiteAllocation::query()->sole();

        $allocator = app(AllocatePackageWebsite::class);

        expect($allocator->handle($subscription)?->id)->toBe($first->id)
            ->and($allocator->handle($subscription)?->id)->toBe($first->id)
            ->and(WebsiteAllocation::query()->count())->toBe(1);
    });

    it('refuses a second allocation for the same purchase at the database', function () {
        websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $first = WebsiteAllocation::query()->sole();

        expect(fn () => WebsiteAllocation::create([
            'business_account_id' => $first->business_account_id,
            'user_package_id' => $first->user_package_id,
            'starts_at' => now(),
            'domain_starts_at' => now(),
            'hosting_starts_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('allocates a renewal once when its payment callback is delivered twice', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $current = UserPackage::query()->findOrFail($account->current_user_package_id);

        $this->travelTo(CarbonImmutable::parse('2027-10-01 09:30:00'));
        $renewal = app(OpenRenewal::class)->handle($account, $current);

        app(ActivateRenewal::class)->handle($renewal);
        app(ActivateRenewal::class)->handle($renewal);

        expect(WebsiteAllocation::query()->where('user_package_id', $renewal->id)->count())->toBe(1)
            ->and(WebsiteAllocation::query()->count())->toBe(2);
    });
});

describe('renewals and package changes', function () {
    it('opens a new period from the renewed term, for the same website', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders']);

        $website = Website::query()->sole();
        $first = WebsiteAllocation::query()->sole();
        $current = UserPackage::query()->findOrFail($account->current_user_package_id);

        // Renewed early: the new term begins when the old one ends.
        $this->travelTo(CarbonImmutable::parse('2027-10-01 09:30:00'));
        $renewal = app(ActivateRenewal::class)->handle(app(OpenRenewal::class)->handle($account, $current));

        $renewed = WebsiteAllocation::query()->where('user_package_id', $renewal->id)->sole();

        expect($renewed->website_id)->toBe($website->id)
            ->and($renewed->previous_allocation_id)->toBe($first->id)
            ->and($renewed->starts_at->equalTo($renewal->started_at))->toBeTrue()
            // The new year follows on from the old one.
            ->and($renewed->domain_starts_at->toDateTimeString())->toBe('2027-10-10 09:30:00')
            ->and($renewed->domain_expires_at?->toDateTimeString())->toBe('2028-10-10 09:30:00')
            ->and($renewed->hosting_expires_at?->toDateTimeString())->toBe('2028-10-10 09:30:00')
            // And the term it renews is left exactly as it was.
            ->and($first->refresh()->domain_expires_at?->toDateTimeString())->toBe('2027-10-10 09:30:00');

        // One website, one card: the renewal's dates, not the old term's.
        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allocations', 1)
                ->where('allocations.0.id', $renewed->public_id));
    });

    it('never shortens a domain term when the subscription year ends a day early', function () {
        // 365 days from 10 October 2027 is 9 October 2028 — 2028 is a leap
        // year — while the domain's twelve months run to 10 October 2028.
        $this->travelTo(CarbonImmutable::parse('2027-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $current = UserPackage::query()->findOrFail($account->current_user_package_id);

        expect($current->expires_at?->toDateString())->toBe('2028-10-09');

        $this->travelTo(CarbonImmutable::parse('2028-10-01 09:30:00'));
        $renewal = app(ActivateRenewal::class)->handle(app(OpenRenewal::class)->handle($account, $current));
        $renewed = WebsiteAllocation::query()->where('user_package_id', $renewal->id)->sole();

        expect($renewal->started_at?->toDateString())->toBe('2028-10-09')
            // Starts where the paid domain term ends, not a day before it…
            ->and($renewed->domain_starts_at->toDateTimeString())->toBe('2028-10-10 09:30:00')
            // …so the renewed year is a full year after the old one.
            ->and($renewed->domain_expires_at?->toDateTimeString())->toBe('2029-10-10 09:30:00');
    });

    it('keeps the months still left on a longer domain term when the package renews', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage([
            PackageFeature::DomainValidityMonths->value => '24',
        ])));
        $current = UserPackage::query()->findOrFail($account->current_user_package_id);

        $this->travelTo(CarbonImmutable::parse('2027-10-01 09:30:00'));
        $renewal = app(ActivateRenewal::class)->handle(app(OpenRenewal::class)->handle($account, $current));
        $renewed = WebsiteAllocation::query()->where('user_package_id', $renewal->id)->sole();

        // The first two-year domain term runs to October 2028; the renewal's
        // two years are added after it, not over the top of it. Hosting, on
        // its own twelve months, renews from the end of its own year.
        expect($renewed->domain_starts_at->toDateString())->toBe('2028-10-10')
            ->and($renewed->domain_expires_at?->toDateString())->toBe('2030-10-10')
            ->and($renewed->hosting_starts_at->toDateString())->toBe('2027-10-10')
            ->and($renewed->hosting_expires_at?->toDateString())->toBe('2028-10-10');
    });

    it('keeps the running period through an upgrade', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders']);

        $first = WebsiteAllocation::query()->sole();
        $current = UserPackage::query()->findOrFail($account->current_user_package_id);
        $larger = websiteAllocationWebsitePackage(fee: '9000.00');

        $this->travelTo(CarbonImmutable::parse('2027-02-01 09:30:00'));
        $change = app(OpenPackageChange::class)->handle($account, $current, $larger);
        app(ActivatePackageChange::class)->handle($change);

        $upgraded = WebsiteAllocation::query()->where('user_package_id', $change->id)->sole();

        expect($upgraded->website_id)->toBe($first->website_id)
            ->and($upgraded->previous_allocation_id)->toBe($first->id)
            ->and($upgraded->domain_starts_at->equalTo($first->domain_starts_at))->toBeTrue()
            ->and($upgraded->hosting_starts_at->equalTo($first->hosting_starts_at))->toBeTrue()
            ->and($upgraded->domain_expires_at?->equalTo($first->domain_expires_at))->toBeTrue()
            ->and($upgraded->hosting_expires_at?->equalTo($first->hosting_expires_at))->toBeTrue();
    });

    it('starts a period when an upgrade adds a website the old package did not have', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationPackage([
            PackageFeature::DedicatedWebsite->value => '0',
        ])));
        $current = UserPackage::query()->findOrFail($account->current_user_package_id);

        $this->travelTo(CarbonImmutable::parse('2027-02-01 09:30:00'));
        $change = app(OpenPackageChange::class)->handle($account, $current, websiteAllocationWebsitePackage(fee: '9000.00'));
        $change = app(ActivatePackageChange::class)->handle($change);

        $allocation = WebsiteAllocation::query()->sole();

        expect($allocation->user_package_id)->toBe($change->id)
            ->and($allocation->starts_at->equalTo($change->started_at))->toBeTrue();
    });
});

describe('who can see an allocation', function () {
    it('shows an account only its own', function () {
        $mine = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));
        $theirs = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));

        $mineAllocation = WebsiteAllocation::query()->where('business_account_id', $mine->id)->sole();
        $theirAllocation = WebsiteAllocation::query()->where('business_account_id', $theirs->id)->sole();

        $this->actingAs($mine->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('allocations', 1)
                ->where('allocations.0.id', $mineAllocation->public_id))
            ->assertDontSee($theirAllocation->public_id);
    });

    it('keeps guests out', function () {
        $this->get(route('websites.index'))->assertRedirect(route('login'));
    });

    it('recognises a subscription that was already active before allocations existed', function () {
        // Activated the old way: no allocation was written at the time.
        $account = websiteTestAccount();

        expect(WebsiteAllocation::query()->count())->toBe(0);

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page->has('allocations', 1));

        $this->actingAs($account->owner)->get(route('websites.index'));

        expect(WebsiteAllocation::query()->count())->toBe(1);
    });

    it('does not let an account stuck in onboarding reach the page', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertRedirect();
    });
});

describe('what the package allows', function () {
    it('lets the partner set up the allocated website, and no more than the package allows', function () {
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage()));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entitlement.limit', 1)
                ->where('entitlement.remaining', 1)
                ->where('entitlement.expired', false));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders'])
            ->assertSessionHasNoErrors();

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Two', 'subdomain' => 'karim-two'])
            ->assertSessionHasErrors(['package']);

        expect(Website::query()->count())->toBe(1);
    });

    it('lets a subscription taken without a website limit still set up the one website it was allocated', function () {
        // Snapshotted before the package said how many websites it allows.
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationPackage([
            PackageFeature::DedicatedWebsite->value => '1',
            PackageFeature::DomainIncluded->value => '1',
            PackageFeature::HostingIncluded->value => '1',
        ])));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entitlement.remaining', 1)
                ->where('allocations.0.status', 'pending_setup'));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders'])
            ->assertSessionHasNoErrors();

        expect(WebsiteAllocation::query()->sole()->website_id)->toBe(Website::query()->sole()->id);

        // The allocation now has its website; nothing else was allowed.
        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Two', 'subdomain' => 'karim-two'])
            ->assertSessionHasErrors(['package']);

        expect(Website::query()->count())->toBe(1);
    });

    it('refuses a website on an entitlement whose period has run out, on the page and at the server', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $account = websiteAllocationConfirm(websiteAllocationPurchase(websiteAllocationWebsitePackage([
            PackageFeature::DomainValidityMonths->value => '1',
            PackageFeature::HostingValidityMonths->value => '1',
        ])));

        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:30:00'));

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entitlement.allowed', true)
                ->where('entitlement.expired', true)
                ->where('entitlement.remaining', 0));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders'])
            ->assertSessionHasErrors(['package' => __('website.refused.entitlement_expired')]);

        expect(Website::query()->count())->toBe(0);
    });

    it('allows setup again once a new term opens the next period', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));
        $package = websiteAllocationWebsitePackage([
            PackageFeature::DomainValidityMonths->value => '1',
            PackageFeature::HostingValidityMonths->value => '1',
        ]);
        $account = websiteAllocationConfirm(websiteAllocationPurchase($package));

        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:30:00'));

        app(AssignPackage::class)->handle(
            $account, $package, User::factory()->create(), 'Renewed by arrangement', CarbonImmutable::now(),
        );

        $this->actingAs($account->owner)
            ->get(route('websites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entitlement.expired', false)
                ->where('entitlement.remaining', 1)
                ->where('allocations.0.status', 'pending_setup'));

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Karim Traders', 'subdomain' => 'karim-traders'])
            ->assertSessionHasNoErrors();

        expect(Website::query()->count())->toBe(1);
    });
});
