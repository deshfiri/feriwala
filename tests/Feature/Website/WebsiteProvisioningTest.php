<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Asking for a website, paying for it, and who may (§16.2, §16.4, P5-8–P5-10).
 *
 * Every figure on these screens is the server's. The tests hold the three
 * things a browser must never decide: whether the package includes a website,
 * what it costs, and whether it has been paid for.
 */
beforeEach(function () {
    Notification::fake();

    // Platform roles exist as rows; a fixture holding one needs them seeded.
    $this->seed(RolesAndPermissionsSeeder::class);

    websiteTestFee(FeeType::WebsiteSetup, 500000);
    websiteTestFee(FeeType::WebsiteDomain, 150000);
    websiteTestFee(FeeType::WebsiteHosting, 200000);
});

describe('asking for a website', function () {
    it('opens it in setup pending with the charges it was quoted', function () {
        $account = websiteTestAccount();

        $this->actingAs($account->owner)
            ->post(route('websites.store'), [
                'name' => 'Nasrin Fashion',
                'subdomain' => 'nasrin-fashion',
            ])
            ->assertRedirect();

        $website = Website::query()->firstOrFail();

        expect($website->status)->toBe(WebsiteStatus::SetupPending)
            ->and($website->business_account_id)->toBe($account->id)
            ->and($website->subdomain)->toBe('nasrin-fashion')
            ->and($website->setup_fee_minor->minorUnits)->toBe(500000)
            // The first status is recorded like every other: a history that
            // starts at the first change cannot say where it began.
            ->and($website->statusHistory()->count())->toBe(1);

        expect(WebsiteCharge::query()->where('website_id', $website->id)->pluck('type')->all())
            ->toEqualCanonicalizing([
                WebsiteChargeType::Setup,
                WebsiteChargeType::Domain,
                WebsiteChargeType::Hosting,
            ]);
    });

    it('charges nothing for a domain or hosting the package already includes', function () {
        $account = websiteTestAccount(extra: [
            PackageFeature::DomainIncluded->value => '1',
            PackageFeature::HostingIncluded->value => '1',
        ]);

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Included', 'subdomain' => 'included-shop']);

        $website = Website::query()->firstOrFail();

        expect($website->domain_fee_minor->minorUnits)->toBe(0)
            ->and($website->hosting_fee_minor->minorUnits)->toBe(0)
            ->and(WebsiteCharge::query()->where('website_id', $website->id)->pluck('type')->all())
            ->toEqual([WebsiteChargeType::Setup]);
    });

    it('refuses an account whose package does not include one', function () {
        $account = websiteTestAccount(entitled: false);

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Nope', 'subdomain' => 'nope-shop'])
            ->assertSessionHasErrors('package');

        expect(Website::query()->count())->toBe(0);
    });

    it('refuses once the package limit is used up', function () {
        $account = websiteTestAccount(limit: 1);
        Website::factory()->forAccount($account)->create();

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Second', 'subdomain' => 'second-shop'])
            ->assertSessionHasErrors('package');

        expect(Website::query()->count())->toBe(1);
    });

    it('counts a closed website against nothing', function () {
        $account = websiteTestAccount(limit: 1);
        Website::factory()->forAccount($account)->create(['status' => WebsiteStatus::Closed]);

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Fresh start', 'subdomain' => 'fresh-start'])
            ->assertRedirect();

        expect(Website::query()->where('status', WebsiteStatus::Active->value)->count())->toBe(0)
            ->and(Website::query()->count())->toBe(2);
    });

    it('refuses an address somebody else holds, and the platform\'s own names', function () {
        $account = websiteTestAccount();
        Website::factory()->create(['subdomain' => 'taken-name']);

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Clash', 'subdomain' => 'taken-name'])
            ->assertSessionHasErrors('subdomain');

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Clash', 'subdomain' => 'admin'])
            ->assertSessionHasErrors('subdomain');
    });

    it('is not open to a staff member who does not speak for the business', function () {
        $account = websiteTestAccount();

        $staff = User::factory()->staffOf($account, AccountRole::Staff)->create();

        $this->actingAs($staff)
            ->post(route('websites.store'), ['name' => 'Staff', 'subdomain' => 'staff-shop'])
            ->assertForbidden();
    });
});

describe('paying for a website', function () {
    it('takes the charge from the wallet and starts the build once nothing is owed', function () {
        $account = websiteTestAccount();
        $wallet = websiteTestWallet($account, '10000.00');

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'Payable', 'subdomain' => 'payable-shop']);

        $website = Website::query()->firstOrFail();

        foreach (WebsiteCharge::query()->where('website_id', $website->id)->get() as $charge) {
            $this->actingAs($account->owner)
                ->post(route('websites.charges.pay', [$website->public_id, $charge->public_id]))
                ->assertRedirect(route('websites.show', $website->public_id));
        }

        // 850,000 of charges against a million.
        expect($wallet->refresh()->total->toDecimal())->toBe('1500.00')
            ->and($website->refresh()->status)->toBe(WebsiteStatus::Development)
            ->and(WebsiteCharge::query()->where('website_id', $website->id)->outstanding()->count())->toBe(0);
    });

    it('pays a charge once however many times the button is pressed', function () {
        $account = websiteTestAccount();
        $wallet = websiteTestWallet($account, '10000.00');
        $website = Website::factory()->forAccount($account)->create();

        $charge = WebsiteCharge::factory()->create([
            'website_id' => $website->id,
            'business_account_id' => $account->id,
            'amount_minor' => 500000,
        ]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($account->owner)
                ->post(route('websites.charges.pay', [$website->public_id, $charge->public_id]));
        }

        expect($wallet->refresh()->total->toDecimal())->toBe('5000.00')
            ->and($charge->refresh()->status)->toBe(WebsiteChargeStatus::Paid)
            ->and($charge->wallet_transaction_id)->not->toBeNull();
    });

    it('asks for a deposit rather than charging a wallet that cannot cover it', function () {
        $account = websiteTestAccount();
        $wallet = websiteTestWallet($account, '10.00');
        $website = Website::factory()->forAccount($account)->create();

        $charge = WebsiteCharge::factory()->create([
            'website_id' => $website->id,
            'business_account_id' => $account->id,
            'amount_minor' => 500000,
        ]);

        $this->actingAs($account->owner)
            ->from(route('websites.show', $website->public_id))
            ->post(route('websites.charges.pay', [$website->public_id, $charge->public_id]))
            ->assertSessionHasErrors('wallet');

        expect($wallet->refresh()->total->toDecimal())->toBe('10.00')
            ->and($charge->refresh()->status)->toBe(WebsiteChargeStatus::Due)
            ->and($website->refresh()->status)->toBe(WebsiteStatus::DepositPending);
    });

    it('never pays another account\'s charge', function () {
        $mine = websiteTestAccount();
        websiteTestWallet($mine, '10000.00');

        $theirs = websiteTestAccount();
        $website = Website::factory()->forAccount($theirs)->create();
        $charge = WebsiteCharge::factory()->create([
            'website_id' => $website->id,
            'business_account_id' => $theirs->id,
        ]);

        $this->actingAs($mine->owner)
            ->post(route('websites.charges.pay', [$website->public_id, $charge->public_id]))
            ->assertNotFound();

        expect($charge->refresh()->status)->toBe(WebsiteChargeStatus::Due);
    });
});

describe('reading a website', function () {
    it('is found only inside the reader\'s own account', function () {
        $mine = websiteTestAccount();
        $theirs = websiteTestAccount();

        $ours = Website::factory()->forAccount($mine)->create();
        $others = Website::factory()->forAccount($theirs)->create();

        $this->actingAs($mine->owner)
            ->get(route('websites.show', $ours->public_id))
            ->assertOk();

        $this->actingAs($mine->owner)
            ->get(route('websites.show', $others->public_id))
            ->assertNotFound();
    });

    it('never carries the platform\'s own notes or suppliers to the partner', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        $website->domains()->create([
            'domain' => 'partner-shop.example',
            'status' => 'active',
            'registrar' => 'Internal registrar',
            'currency_code' => 'BDT',
            'fee_minor' => 150000,
            'registered_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        $website->hostings()->create([
            'plan' => 'Storefront standard',
            'provider' => 'Secret host',
            'status' => 'active',
            'currency_code' => 'BDT',
            'fee_minor' => 200000,
            'started_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        $response = $this->actingAs($account->owner)
            ->get(route('websites.show', $website->public_id));

        $response->assertOk();
        $response->assertDontSee('Internal registrar');
        $response->assertDontSee('Secret host');
    });

    it('opens the navigation door for the account, and the platform list only with the permission', function () {
        $account = websiteTestAccount();
        Website::factory()->forAccount($account)->create();

        // Read out of the rendered page rather than Inertia's dotted helper,
        // which would read `permissions.website.view` as three nested keys.
        $partnerPage = $this->actingAs($account->owner)->get(route('websites.index'));
        $partnerPage->assertOk();

        $partnerProps = $partnerPage->viewData('page')['props'];

        expect($partnerProps['account']->allowsWebsites)->toBeTrue()
            ->and($partnerProps['account']->hasWebsites)->toBeTrue()
            ->and($partnerProps['permissions']['website.view'] ?? null)->toBeFalse();

        $staffPage = $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->get(route('admin.websites.index'));

        $staffPage->assertOk();

        expect($staffPage->viewData('page')['props']['permissions']['website.view'] ?? null)->toBeTrue();
    });

    it('refuses the platform panel to a partner, whatever they hold', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->create();

        $this->actingAs($account->owner)
            ->get(route('admin.websites.index'))
            ->assertForbidden();

        $this->actingAs($account->owner)
            ->get(route('admin.websites.show', $website->public_id))
            ->assertForbidden();
    });
});

describe('maintenance mode', function () {
    it('is the partner\'s own switch, both ways', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        $this->actingAs($account->owner)
            ->put(route('websites.maintenance.update', $website->public_id), [
                'enabled' => true,
                'message' => 'Back in an hour.',
            ])
            ->assertRedirect();

        expect($website->refresh()->status)->toBe(WebsiteStatus::Maintenance)
            ->and($website->maintenance_message)->toBe('Back in an hour.');

        $this->actingAs($account->owner)
            ->put(route('websites.maintenance.update', $website->public_id), ['enabled' => false]);

        expect($website->refresh()->status)->toBe(WebsiteStatus::Active)
            ->and($website->maintenance_message)->toBeNull();
    });

    it('refuses a move the status map does not allow', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->create(['status' => WebsiteStatus::SetupPending]);

        $this->actingAs($account->owner)
            ->from(route('websites.show', $website->public_id))
            ->put(route('websites.maintenance.update', $website->public_id), ['enabled' => true])
            ->assertSessionHasErrors('status');

        expect($website->refresh()->status)->toBe(WebsiteStatus::SetupPending);
    });
});
