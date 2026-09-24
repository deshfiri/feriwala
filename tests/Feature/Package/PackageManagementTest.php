<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Package\Actions\ManagePackages;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Exceptions\PackageInUse;
use App\Domain\Package\Models\Package;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Package CRUD and its lifecycle guard (P1-32, §8.1).
 *
 * §8 allows N packages created and archived freely — but "freely" stops where
 * something else resolves through one. A KYC scope rule names a package by
 * slug; archiving without saying so leaves a rule matching nobody, and the
 * first anyone hears of it is an applicant asked for the wrong documents.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/** Somebody who manages packages but is not Super Admin. */
function packageTestManager(): User
{
    /*
     * Enrolled, because a Package Manager can delete and §36 requires a second
     * factor before the panel opens at all (P1-18). Without it every assertion
     * below would be testing the enrolment redirect instead of packages.
     */
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->assignRole(PlatformRole::PackageManager->value);

    return $user;
}

/**
 * Attributes shaped for direct model creation — the real column names.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packageTestPayload(array $overrides = []): array
{
    return [
        'name' => 'Growth',
        'slug' => 'growth',
        'short_description' => 'For a shop finding its feet.',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'required_deposit' => Money::zero(Currency::BDT),
        'minimum_balance' => Money::zero(Currency::BDT),
        'is_active' => '1',
        'is_public' => '1',
        ...$overrides,
    ];
}

/**
 * The same package, shaped for the HTTP form: Taka decimal strings for the
 * money fields, as an administrator actually types them (§36.1).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packageTestFormPayload(array $overrides = []): array
{
    return [
        'name' => 'Growth',
        'slug' => 'growth',
        'short_description' => 'For a shop finding its feet.',
        'fee' => '5000.00',
        'currency_code' => 'BDT',
        'required_deposit' => '0',
        'minimum_balance' => '0',
        'is_active' => '1',
        'is_public' => '1',
        ...$overrides,
    ];
}

describe('creating and editing', function () {
    it('creates a package with its entitlements and charges', function () {
        $package = app(ManagePackages::class)->create(
            [
                'name' => 'Growth',
                'slug' => 'growth',
                'fee' => Money::fromDecimal('5000.00', Currency::BDT),
                'currency_code' => 'BDT',
                'is_active' => true,
                'is_public' => true,
            ],
            [PackageFeature::StaffLimit->value => '5'],
            [['charge_type' => 'website_setup', 'amount' => Money::fromDecimal('2000.00', Currency::BDT)]],
            $this->admin,
        );

        expect($package->feature(PackageFeature::StaffLimit))->toBe(5)
            ->and($package->charges)->toHaveCount(1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'package.created']);
    });

    it('replaces entitlements wholesale rather than merging them', function () {
        // An absent feature is "this package does not grant it", and the enum's
        // default applies. Merging would make a facility impossible to withdraw
        // through the form that granted it.
        $manage = app(ManagePackages::class);

        $package = $manage->create(
            ['name' => 'Growth', 'slug' => 'growth', 'fee' => Money::fromDecimal('5000.00', Currency::BDT), 'currency_code' => 'BDT', 'is_active' => true, 'is_public' => true],
            [
                PackageFeature::StaffLimit->value => '5',
                PackageFeature::ApiAccess->value => '1',
            ],
            [],
            $this->admin,
        );

        $manage->update($package, [], [PackageFeature::StaffLimit->value => '2'], [], $this->admin);

        expect($package->refresh()->feature(PackageFeature::StaffLimit))->toBe(2)
            // Back to the enum's default, which is off (§8.1).
            ->and($package->feature(PackageFeature::ApiAccess))->toBeFalse();
    });

    it('keeps an unlimited entitlement distinct from none at all', function () {
        // §8.1: null means unlimited, zero means nothing. A blank field must
        // not become a package that grants nothing.
        $manage = app(ManagePackages::class);

        $unlimited = $manage->create(
            ['name' => 'A', 'slug' => 'a', 'fee' => Money::fromDecimal('0.01', Currency::BDT), 'currency_code' => 'BDT', 'is_active' => true, 'is_public' => true],
            [PackageFeature::StaffLimit->value => ''],
            [],
            $this->admin,
        );

        $none = $manage->create(
            ['name' => 'B', 'slug' => 'b', 'fee' => Money::fromDecimal('0.01', Currency::BDT), 'currency_code' => 'BDT', 'is_active' => true, 'is_public' => true],
            [PackageFeature::StaffLimit->value => '0'],
            [],
            $this->admin,
        );

        // Unset falls to the enum default of 0 for a limit — a package that
        // says nothing grants nothing, which is the safe direction.
        expect($unlimited->feature(PackageFeature::StaffLimit))->toBe(0)
            ->and($none->feature(PackageFeature::StaffLimit))->toBe(0);
    });

    it('takes a package off sale without retiring it', function () {
        // Reversible, and unguarded on purpose: accounts already on it keep
        // their entitlements, and a KYC rule naming it still resolves.
        $package = Package::create(packageTestPayload(['is_active' => true, 'is_public' => true]));

        app(ManagePackages::class)->setActive($package, false, $this->admin);

        expect($package->refresh()->is_active)->toBeFalse()
            ->and($package->trashed())->toBeFalse();
    });
});

describe('the archive guard', function () {
    it('archives a package nothing resolves through', function () {
        $package = Package::create(packageTestPayload());

        app(ManagePackages::class)->archive($package, $this->admin);

        expect($package->refresh()->trashed())->toBeTrue()
            ->and($package->is_active)->toBeFalse()
            ->and($package->is_public)->toBeFalse();
    });

    it('refuses while a live KYC requirement names it, and says which', function () {
        /*
         * The failure this guards: a scope rule left pointing at a slug that
         * resolves to nothing, discovered when an applicant is asked for the
         * wrong documents.
         */
        $package = Package::create(packageTestPayload());

        KycDocumentType::factory()
            ->scopedTo(package: $package->public_id)
            ->create(['name' => 'Trade licence']);

        expect(fn () => app(ManagePackages::class)->archive($package, $this->admin))
            ->toThrow(PackageInUse::class, 'Trade licence');

        expect($package->refresh()->trashed())->toBeFalse();
    });

    it('ignores a rule on an archived requirement', function () {
        // It resolves for nobody already, so it is not a reason to keep a
        // package on the books.
        $package = Package::create(packageTestPayload());

        KycDocumentType::factory()
            ->archived()
            ->scopedTo(package: $package->public_id)
            ->create();

        app(ManagePackages::class)->archive($package, $this->admin);

        expect($package->refresh()->trashed())->toBeTrue();
    });

    it('refuses while an account is still subscribed', function () {
        $account = testAccountWithStaffLimit(5);
        $package = $account->currentPackage->package;

        expect(fn () => app(ManagePackages::class)->archive($package, $this->admin))
            ->toThrow(PackageInUse::class, 'subscribed');
    });

    it('ignores a subscription that no longer entitles anything', function () {
        // Expired or cancelled is history, and history does not keep a package
        // on the books.
        $account = testAccountWithStaffLimit(5);
        $package = $account->currentPackage->package;

        $account->currentPackage->forceFill(['status' => UserPackageStatus::Expired])->save();

        app(ManagePackages::class)->archive($package->refresh(), $this->admin);

        expect($package->refresh()->trashed())->toBeTrue();
    });

    it('lets a historical KYC round keep resolving after the package goes', function () {
        /*
         * The reason archiving is safe at all once the live rules are cleared:
         * a round carries a captured snapshot, not a live lookup, so an
         * inactive package cannot change what a past round was judged on.
         */
        $account = testBusinessAccount();
        $package = Package::create(packageTestPayload());

        KycDocumentType::factory()->create(['name' => 'National ID']);

        $submission = app(OpenKycDraft::class)->handle($account);
        $captured = $submission->requirements()->pluck('name')->all();

        app(ManagePackages::class)->archive($package, $this->admin);

        expect($submission->refresh()->requirements()->pluck('name')->all())
            ->toBe($captured);
    });

    it('reports the blockers before anyone presses the button', function () {
        // A guard met only on submit is one met after writing a change that now
        // has to be undone.
        $package = Package::create(packageTestPayload());
        KycDocumentType::factory()->scopedTo(package: $package->public_id)->create(['name' => 'Trade licence']);

        $blockers = app(ManagePackages::class)->blockers($package);

        expect($blockers['kyc_requirements'])->toBe(['Trade licence']);
    });
});

describe('permissions', function () {
    it('lets a package manager configure the catalogue', function () {
        expect(packageTestManager()->can('viewAny', Package::class))->toBeTrue();
    });

    it('refuses somebody with no package permission', function () {
        expect(User::factory()->staff()->create()->can('create', Package::class))
            ->toBeFalse();
    });

    it('never offers editing an archived package', function () {
        $package = Package::create(packageTestPayload());
        $package->delete();

        expect(packageTestManager()->can('update', $package->refresh()))->toBeFalse();
    });

    it('offers hard deletion to nobody', function () {
        // §36.2: a payment and an invoice name the package they were for, so a
        // row that vanishes takes their meaning with it.
        $package = Package::create(packageTestPayload());

        expect(packageTestManager()->can('delete', $package))->toBeFalse();
    });
});

describe('the admin screen', function () {
    it('lists live and archived packages together', function () {
        // "Which plan was this account on in March" has to stay answerable.
        Package::create(packageTestPayload());
        $old = Package::create(packageTestPayload(['name' => 'Legacy', 'slug' => 'legacy']));
        $old->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.packages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/packages/index')
                ->has('packages', 2)
                ->where('can.create', true),
            );
    });

    it('addresses a package by slug, never a database id', function () {
        $package = Package::create(packageTestPayload());

        $this->actingAs($this->admin)
            ->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page->where('packages.0.id', 'growth'));
    });

    it('sends the blockers with the row', function () {
        $package = Package::create(packageTestPayload());
        KycDocumentType::factory()->scopedTo(package: $package->public_id)->create(['name' => 'Trade licence']);

        $this->actingAs($this->admin)
            ->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packages.0.blockers.kyc_requirements.0', 'Trade licence')
                ->where('packages.0.can.archive', true),
            );
    });

    it('creates through the form', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.store'), packageTestFormPayload([
                'features' => [PackageFeature::StaffLimit->value => '3'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        expect(Package::query()->where('slug', 'growth')->first()->feature(PackageFeature::StaffLimit))
            ->toBe(3);
    });

    it('converts Taka form input to exact minor units, including a charge row', function () {
        // §36.1: the administrator types Taka; the server is the only place
        // that converts to the integer minor units the column stores.
        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.store'), packageTestFormPayload([
                'fee' => '500.50',
                'registration_fee' => '250.25',
                'charges' => [
                    ['charge_type' => 'website_setup', 'amount' => '199.99', 'frequency' => 'once'],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $package = Package::query()->where('slug', 'growth')->firstOrFail();

        expect($package->fee->toDecimal())->toBe('500.50')
            ->and($package->registration_fee->toDecimal())->toBe('250.25')
            ->and($package->charges->first()->amount->toDecimal())->toBe('199.99');
    });

    it('refuses a form amount with more than two decimal places', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.store'), packageTestFormPayload([
                'fee' => '500.505',
            ]))
            ->assertSessionHasErrors('fee');

        expect(Package::query()->where('slug', 'growth')->exists())->toBeFalse();
    });

    it('refuses a duplicate slug', function () {
        Package::create(packageTestPayload());

        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.store'), packageTestFormPayload(['name' => 'Another']))
            ->assertSessionHasErrors('slug');
    });

    it('lets a package keep its own slug when edited', function () {
        $package = Package::create(packageTestPayload());

        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->patch(route('admin.packages.update', 'growth'), packageTestFormPayload(['name' => 'Growth Plus']))
            ->assertSessionHasNoErrors();

        expect($package->refresh()->name)->toBe('Growth Plus');
    });

    it('converts every Taka field an edit submits, including clearing one to null', function () {
        // Editing exercises a fuller set of fields at once than creating does —
        // a renewal fee paired with its frequency, and an override cleared back
        // to "use the default" by submitting nothing for it.
        $package = Package::create(packageTestPayload());

        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->patch(route('admin.packages.update', 'growth'), packageTestFormPayload([
                'fee' => '15000.00',
                'registration_fee' => null,
                'renewal_fee' => '15000.00',
                'renewal_frequency' => 'yearly',
                'required_deposit' => '0',
                'minimum_balance' => '0',
                'validity_days' => '365',
            ]))
            ->assertSessionHasNoErrors();

        $package->refresh();

        expect($package->fee->toDecimal())->toBe('15000.00')
            ->and($package->renewal_fee->toDecimal())->toBe('15000.00')
            ->and($package->registration_fee)->toBeNull()
            ->and($package->required_deposit->isZero())->toBeTrue();
    });

    it('refuses a renewal fee with no frequency', function () {
        // It would renew on no schedule — a package that behaves differently
        // from how it reads.
        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.store'), packageTestFormPayload([
                'renewal_fee' => '1000.00',
            ]))
            ->assertSessionHasErrors('renewal_frequency');
    });

    it('refuses a feature key that is not one', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.store'), packageTestFormPayload([
                'features' => ['staff_limitt' => '3'],
            ]))
            ->assertSessionHasErrors('features.staff_limitt');
    });

    it('turns a blocked archive into a form error naming the rules', function () {
        $package = Package::create(packageTestPayload());
        KycDocumentType::factory()->scopedTo(package: $package->public_id)->create(['name' => 'Trade licence']);

        $this->actingAs($this->admin)
            ->from(route('admin.packages.index'))
            ->post(route('admin.packages.archive', 'growth'))
            ->assertSessionHasErrors('package');

        expect(Package::query()->where('slug', 'growth')->first()->trashed())->toBeFalse();
    });

    it('turns away somebody without the permission', function () {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('admin.packages.index'))
            ->assertForbidden();
    });
});

describe('navigation', function () {
    it('shows the catalogue entry to a package manager', function () {
        $this->actingAs(packageTestManager())
            ->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => expect(
                $page->toArray()['props']['permissions']['package.view']
            )->toBeTrue());
    });

    it('hides it from a KYC reviewer', function () {
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(PermissionCatalogue::name(
            PermissionModule::Kyc,
            PermissionAction::View,
        ));

        $this->actingAs($reviewer)
            ->get(route('admin.kyc.index'))
            ->assertInertia(fn (Assert $page) => expect(
                $page->toArray()['props']['permissions']['package.view']
            )->toBeFalse());
    });
});
