<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\Actions\SweepWebsiteLifecycle;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Notifications\Website\WebsiteRenewalDue;
use App\Notifications\Website\WebsiteStatusChanged;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * The website clock, and the moves a person makes by hand (§16.4, §24.3, P5-9, P5-11, P5-15).
 *
 * Nothing here happens because somebody clicked something: a package lapses, a
 * domain reaches its last month, a balance falls below what was agreed. The
 * tests hold the sweep to doing each of those once, and to leaving alone what is
 * not its business.
 */
beforeEach(function () {
    Notification::fake();

    websiteTestFee(FeeType::WebsiteSetup, 500000);
    websiteTestFee(FeeType::WebsiteDomain, 150000);
    websiteTestFee(FeeType::WebsiteHosting, 200000);
});

describe('the lifecycle sweep', function () {
    it('gives a lapsed package its grace period, then expires it', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        // The subscription lapses: the website is live, and the package that
        // entitled it is not.
        $account->currentPackage()->update(['status' => UserPackageStatus::Expired]);

        $counts = app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::GracePeriod)
            ->and($website->grace_ends_at)->not->toBeNull()
            ->and($counts['grace'])->toBe(1);

        // Still inside the grace period: nothing more happens.
        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::GracePeriod);

        $this->travel(8)->days();

        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::PackageExpired);

        Notification::assertSentTo($account->owner, WebsiteStatusChanged::class);
    });

    it('brings a website back when the package entitles it again', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->create([
            'status' => WebsiteStatus::PackageExpired,
        ]);

        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::Active);
    });

    it('flags a wallet that no longer holds what the account agreed to', function () {
        $account = websiteTestAccount();
        $wallet = websiteTestWallet($account, '5000.00');

        // §24 asks for both at once: a deposit to keep and a balance to
        // maintain. Half of it is not meeting it.
        $wallet->forceFill([
            'required_deposit' => Money::fromDecimal('5000.00', Currency::BDT),
            'minimum_balance' => Money::fromDecimal('5000.00', Currency::BDT),
        ])->save();

        $website = Website::factory()->forAccount($account)->active()->create();

        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::LowWalletBalance);

        // Money arrives through the ledger, and the flag clears on the next pass.
        app(WalletService::class)->credit(
            $wallet->refresh(),
            LedgerTransactionType::TopUpCredit,
            Money::fromDecimal('5000.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'Top up'),
        );

        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::Active);
    });

    it('says a domain is falling due, and warns once per stage', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        $domain = WebsiteDomain::factory()->expiringIn(20)->create([
            'website_id' => $website->id,
        ]);

        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::DomainRenewalPending)
            ->and($domain->refresh()->reminder_stage)->toBe(30);

        // A daily sweep must not send the same warning every day.
        app(SweepWebsiteLifecycle::class)->handle();

        Notification::assertSentToTimes($account->owner, WebsiteRenewalDue::class, 1);

        // Closer in, the next stage is a fresh warning.
        $this->travel(10)->days();

        app(SweepWebsiteLifecycle::class)->handle();

        Notification::assertSentToTimes($account->owner, WebsiteRenewalDue::class, 2);
        expect($domain->refresh()->reminder_stage)->toBe(14);
    });

    it('takes a website down when its hosting term runs out', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        $hosting = WebsiteHosting::factory()->create([
            'website_id' => $website->id,
            'expires_at' => now()->subDay(),
        ]);

        app(SweepWebsiteLifecycle::class)->handle();

        expect($hosting->refresh()->status)->toBe(WebsiteServiceStatus::Expired)
            ->and($website->refresh()->status)->toBe(WebsiteStatus::TemporarilyDisabled);
    });

    it('leaves a closed website alone', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->create([
            'status' => WebsiteStatus::Closed,
            'closed_at' => now(),
        ]);

        $account->currentPackage()->update(['status' => UserPackageStatus::Expired]);

        app(SweepWebsiteLifecycle::class)->handle();

        expect($website->refresh()->status)->toBe(WebsiteStatus::Closed)
            ->and($website->statusHistory()->count())->toBe(0);
    });
});

describe('renewing a term', function () {
    it('charges for the next term and extends from the current expiry', function () {
        $account = websiteTestAccount();
        $wallet = websiteTestWallet($account, '10000.00');

        $website = Website::factory()->forAccount($account)->create([
            'status' => WebsiteStatus::DomainRenewalPending,
        ]);

        $domain = WebsiteDomain::factory()->expiringIn(20)->create(['website_id' => $website->id]);
        $expiry = $domain->expires_at;

        $this->actingAs($account->owner)
            ->post(route('websites.domains.renew', [$website->public_id, $domain->id]))
            ->assertRedirect(route('websites.show', $website->public_id));

        expect($wallet->refresh()->total->toDecimal())->toBe('8500.00')
            ->and($domain->refresh()->expires_at?->toDateString())
            ->toBe($expiry?->addMonths(12)->toDateString())
            ->and($domain->reminder_stage)->toBeNull()
            // Paying for the renewal is what makes the shop live again.
            ->and($website->refresh()->status)->toBe(WebsiteStatus::Active);

        $charge = WebsiteCharge::query()->where('website_id', $website->id)->firstOrFail();

        expect($charge->type)->toBe(WebsiteChargeType::Domain)
            ->and($charge->status)->toBe(WebsiteChargeStatus::Paid);
    });

    it('raises one charge for a term, however many times a renewal is attempted', function () {
        $account = websiteTestAccount();
        websiteTestWallet($account, '10.00');

        $website = Website::factory()->forAccount($account)->create([
            'status' => WebsiteStatus::HostingRenewalPending,
        ]);

        $hosting = WebsiteHosting::factory()->expiringIn(10)->create(['website_id' => $website->id]);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($account->owner)
                ->from(route('websites.show', $website->public_id))
                ->post(route('websites.hostings.renew', [$website->public_id, $hosting->id]))
                ->assertSessionHasErrors('wallet');
        }

        expect(WebsiteCharge::query()->where('website_id', $website->id)->count())->toBe(1)
            ->and($website->refresh()->status)->toBe(WebsiteStatus::HostingRenewalPending);
    });
});

describe('the platform moving a website by hand', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
    });

    it('records the move, the reason and the note, and tells the partner', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->post(route('admin.websites.status.store', $website->public_id), [
                'status' => WebsiteStatus::Suspended->value,
                'reason' => 'Selling goods the platform does not permit.',
                'internal_note' => 'Escalated by the catalogue team.',
                'public_note' => 'Your shop has been suspended. Contact support.',
            ])
            ->assertRedirect(route('admin.websites.show', $website->public_id));

        $website->refresh();
        $change = $website->statusHistory()->latest('id')->firstOrFail();

        expect($website->status)->toBe(WebsiteStatus::Suspended)
            ->and($website->suspended_at)->not->toBeNull()
            ->and($change->reason)->toBe('Selling goods the platform does not permit.')
            ->and($change->internal_note)->toBe('Escalated by the catalogue team.')
            ->and($change->public_note)->toBe('Your shop has been suspended. Contact support.');

        Notification::assertSentTo($account->owner, WebsiteStatusChanged::class);
    });

    it('asks for a reason, and refuses a move the map does not allow', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->create([
            'status' => WebsiteStatus::SetupPending,
        ]);

        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)
            ->from(route('admin.websites.show', $website->public_id))
            ->post(route('admin.websites.status.store', $website->public_id), [
                'status' => WebsiteStatus::Suspended->value,
                'reason' => 'no',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($staff)
            ->from(route('admin.websites.show', $website->public_id))
            ->post(route('admin.websites.status.store', $website->public_id), [
                'status' => WebsiteStatus::Active->value,
                'reason' => 'Trying to skip the build entirely.',
            ])
            ->assertSessionHasErrors('status');

        expect($website->refresh()->status)->toBe(WebsiteStatus::SetupPending);
    });

    it('records a domain registration made by hand, and gives the website its address', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->inDevelopment()->create();

        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->post(route('admin.websites.domains.store', $website->public_id), [
                'domain' => 'nasrin-fashion.com.bd',
                'registrar' => 'BTCL',
                'term_months' => 12,
                'make_primary' => true,
            ])
            ->assertRedirect();

        $registration = WebsiteDomain::query()->firstOrFail();

        expect($registration->domain)->toBe('nasrin-fashion.com.bd')
            ->and($registration->status)->toBe(WebsiteServiceStatus::Active)
            ->and($website->refresh()->domain)->toBe('nasrin-fashion.com.bd')
            ->and($website->host())->toBe('nasrin-fashion.com.bd');
    });
});

describe('the history itself', function () {
    it('cannot be rewritten or removed', function () {
        $account = websiteTestAccount();
        $website = Website::factory()->forAccount($account)->active()->create();

        $website->recordStatusChange(
            null,
            WebsiteStatus::Active,
            new StatusChange(reason: 'opening'),
            ['source' => 'system'],
        );

        $change = $website->statusHistory()->firstOrFail();

        expect(fn () => $change->update(['reason' => 'something else']))
            ->toThrow(LogicException::class);

        expect(fn () => $change->delete())->toThrow(LogicException::class);
    });
});
