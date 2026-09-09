<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Actions\AssignPackage;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Notifications\Package\PackageAssigned;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Manual and promotional assignment (P1-40, §8.3).
 *
 * Real entitlement and no money. Everything downstream has to be able to tell
 * it from a sale, and the only honest way is to record it as what it is.
 */

function assignTestPackage(int $feeMinor = 500000, ?int $staffLimit = null): Package
{
    $package = Package::create([
        'slug' => 'grant-'.Str::lower(Str::random(8)),
        'name' => 'Enterprise',
        'fee_minor' => $feeMinor,
        'renewal_fee_minor' => $feeMinor,
        'renewal_frequency' => 'yearly',
        'validity_days' => 365,
        'grace_period_days' => 14,
        'required_deposit_minor' => 0,
        'minimum_balance_minor' => 0,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    if ($staffLimit !== null) {
        $package->features()->create([
            'feature' => PackageFeature::StaffLimit->value,
            'value' => (string) $staffLimit,
        ]);
    }

    return $package->refresh()->load(['features', 'charges']);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = testPlatformStaff(PlatformRole::PackageManager);
    $this->account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
});

describe('granting a package', function () {
    it('creates a live term that nobody paid for', function () {
        $package = assignTestPackage();

        $granted = app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Agreed as part of the launch partnership.',
            startsAt: CarbonImmutable::instance(now()),
            expiresAt: CarbonImmutable::instance(now())->addDays(90),
        );

        expect($granted->status)->toBe(UserPackageStatus::Active)
            ->and($granted->source)->toBe(SubscriptionSource::Manual)
            ->and($granted->source->isPaid())->toBeFalse()
            ->and($granted->paid_fee_minor?->minorUnits)->toBe(0)
            ->and($granted->entitlesNow())->toBeTrue();
    });

    it('creates no payment, real or otherwise', function () {
        // Everything downstream — revenue, refunds, reconciliation — depends on
        // a granted term not looking like a sale.
        $package = assignTestPackage();

        app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Agreed as part of the launch partnership.',
            startsAt: CarbonImmutable::instance(now()),
        );

        expect(Payment::query()->count())->toBe(0);
    });

    it('uses the dates it was given rather than the package validity', function () {
        // §8.3 lists the effective date among the things an administrator
        // configures: a promotion runs for its promotion.
        $package = assignTestPackage();
        $starts = CarbonImmutable::parse('2026-03-01 00:00:00');
        $ends = CarbonImmutable::parse('2026-04-15 00:00:00');

        $granted = app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Six-week trial agreed with the partner team.',
            startsAt: $starts,
            expiresAt: $ends,
        );

        expect($granted->started_at?->toDateString())->toBe('2026-03-01')
            ->and($granted->expires_at?->toDateString())->toBe('2026-04-15')
            // Grace still comes from the terms that were captured.
            ->and($granted->grace_ends_at?->toDateString())->toBe('2026-04-29');
    });

    it('captures the terms, so a later catalogue edit does not reach it', function () {
        $package = assignTestPackage(staffLimit: 25);

        $granted = app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Agreed as part of the launch partnership.',
            startsAt: CarbonImmutable::instance(now()),
        );

        $package->features()->where('feature', PackageFeature::StaffLimit->value)
            ->update(['value' => '2']);

        expect($granted->refresh()->terms()?->feature(PackageFeature::StaffLimit))->toBe(25)
            ->and(app(Entitlements::class)->limit($this->account->refresh(), PackageFeature::StaffLimit))
            ->toBe(25);
    });

    it('records who did it and why, and marks it sensitive', function () {
        $package = assignTestPackage();

        app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Agreed as part of the launch partnership.',
            startsAt: CarbonImmutable::instance(now()),
        );

        $entry = AuditLog::query()->where('action', 'package.manual_assigned')->firstOrFail();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->reason)->toBe('Agreed as part of the launch partnership.')
            ->and($entry->is_sensitive)->toBeTrue();
    });

    it('records a promotional grant as promotional', function () {
        // Distinct from a manual assignment, because a report counting one as
        // the other is reading revenue that means something else.
        $package = assignTestPackage();

        $granted = app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Launch promotion, approved by the commercial team.',
            startsAt: CarbonImmutable::instance(now()),
            promotional: true,
        );

        expect($granted->source)->toBe(SubscriptionSource::Promotional)
            ->and($granted->source->isPaid())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'package.promotional_assigned')->exists())
            ->toBeTrue();
    });

    it('tells the account, without the internal reason', function () {
        // Their limits changed and they did not ask for it (§7.3).
        Notification::fake();

        $package = assignTestPackage();

        app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Compensation for the March outage.',
            startsAt: CarbonImmutable::instance(now()),
        );

        Notification::assertSentTo(
            $this->account->owner,
            PackageAssigned::class,
            function (PackageAssigned $notification) {
                expect(json_encode($notification->toArray($this->account->owner)))
                    ->not->toContain('March outage');

                return true;
            },
        );
    });

    it('requires a reason', function () {
        $package = assignTestPackage();

        expect(fn () => app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: '  ',
            startsAt: CarbonImmutable::instance(now()),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a term that ends before it begins', function () {
        $package = assignTestPackage();

        expect(fn () => app(AssignPackage::class)->handle(
            account: $this->account,
            package: $package,
            actor: $this->admin,
            reason: 'Agreed as part of the launch partnership.',
            startsAt: CarbonImmutable::instance(now()),
            expiresAt: CarbonImmutable::instance(now())->subDay(),
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('what a grant replaces', function () {
    it('closes the term the account was on when it starts now', function () {
        $held = assignTestPackage(300000);
        $granted = assignTestPackage(900000);

        $current = UserPackage::create([
            'business_account_id' => $this->account->id,
            'package_id' => $held->id,
            'status' => UserPackageStatus::Active,
            'source' => SubscriptionSource::Purchase,
            'started_at' => now()->subDays(10),
            'expires_at' => now()->addDays(300),
            'paid_fee_minor' => 300000,
            'currency_code' => 'BDT',
            'terms' => SubscriptionTerms::capture($held)->toArray(),
            'terms_captured_at' => now(),
        ]);

        $this->account->forceFill(['current_user_package_id' => $current->id])->save();

        $new = app(AssignPackage::class)->handle(
            account: $this->account->refresh(),
            package: $granted,
            actor: $this->admin,
            reason: 'Agreed as part of the launch partnership.',
            startsAt: CarbonImmutable::instance(now()),
        );

        expect($current->refresh()->status)->toBe(UserPackageStatus::Cancelled)
            ->and($current->cancelled_at)->not->toBeNull()
            ->and($this->account->refresh()->current_user_package_id)->toBe($new->id);
    });

    it('leaves the current term running when the grant is dated ahead', function () {
        $held = assignTestPackage(300000);
        $granted = assignTestPackage(900000);

        $current = UserPackage::create([
            'business_account_id' => $this->account->id,
            'package_id' => $held->id,
            'status' => UserPackageStatus::Active,
            'source' => SubscriptionSource::Purchase,
            'started_at' => now()->subDays(10),
            'expires_at' => now()->addDays(300),
            'paid_fee_minor' => 300000,
            'currency_code' => 'BDT',
            'terms' => SubscriptionTerms::capture($held)->toArray(),
            'terms_captured_at' => now(),
        ]);

        app(AssignPackage::class)->handle(
            account: $this->account,
            package: $granted,
            actor: $this->admin,
            reason: 'Starts when the current term is halfway through.',
            startsAt: CarbonImmutable::instance(now())->addDays(30),
        );

        expect($current->refresh()->status)->toBe(UserPackageStatus::Active);
    });
});

describe('who may do it', function () {
    it('needs the package settings permission, not merely account access', function () {
        // Writing a plan and giving one away are different decisions.
        $package = assignTestPackage();
        $viewer = testPlatformStaff(PlatformRole::ReportViewer);

        $this->actingAs($viewer)
            ->post(route('admin.accounts.assign-package', $this->account->public_id), [
                'package' => $package->slug,
                'reason' => 'Agreed as part of the launch partnership.',
                'starts_at' => now()->toDateString(),
            ])
            ->assertForbidden();

        expect(UserPackage::query()->count())->toBe(0);
    });

    it('lets a package manager through', function () {
        $package = assignTestPackage();

        $this->actingAs($this->admin)
            ->post(route('admin.accounts.assign-package', $this->account->public_id), [
                'package' => $package->slug,
                'reason' => 'Agreed as part of the launch partnership.',
                'starts_at' => now()->toDateString(),
                'expires_at' => now()->addDays(90)->toDateString(),
            ])
            ->assertRedirect();

        expect(UserPackage::query()->where('source', SubscriptionSource::Manual)->count())->toBe(1);
    });

    it('turns a blank reason into a form error', function () {
        $package = assignTestPackage();

        $this->actingAs($this->admin)
            ->post(route('admin.accounts.assign-package', $this->account->public_id), [
                'package' => $package->slug,
                'reason' => '',
                'starts_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('reason');
    });

    it('offers the control on the account dossier', function () {
        assignTestPackage();

        $this->actingAs($this->admin)
            ->get(route('admin.accounts.show', $this->account->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/accounts/show')
                ->has('assignable_packages', 1),
            );
    });
});
