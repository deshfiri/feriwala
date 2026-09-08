<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Package\Actions\ManagePackages;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The route-level entitlement gate (P1-11, §8.1, §8.4).
 *
 * Hiding a control is presentation. This is the enforcement behind it: a
 * facility an account has not bought refuses the URL as well as the button,
 * because anyone who has ever seen the route can still type it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('a facility the package includes', function () {
    it('opens for an account that bought it', function () {
        $account = testAccountWithStaffLimit(5);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertOk();
    });

    it('opens when the package sets no limit at all', function () {
        // Null is unlimited, and must not be confused with absent.
        $account = testAccountWithStaffLimit(null);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertOk();
    });
});

describe('a facility the package does not include', function () {
    it('refuses the screen', function () {
        // Zero is a real answer — a package that grants no staff at all — and
        // is deliberately distinguishable from unlimited.
        $account = testAccountWithStaffLimit(0);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertForbidden();
    });

    it('refuses every write behind it, not only the page', function () {
        /*
         * The direction that matters. Gating the screen alone leaves the
         * endpoints open to anyone who has seen them once — which is everyone
         * who was on a package that included staff before downgrading.
         */
        $account = testAccountWithStaffLimit(0);

        $this->actingAs($account->owner)
            ->post(route('staff.invitations.store'), [
                'email' => 'someone@example.com',
                'role' => 'staff',
            ])
            ->assertForbidden();
    });

    it('refuses an account with no package at all', function () {
        // §5.4: still in the funnel, or lapsed. Either way the safe answer is
        // no, and a limit reads as zero rather than as unlimited.
        $account = testBusinessAccount(AccountStatus::Active);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertForbidden();
    });
});

describe('what the gate reads', function () {
    it('answers from the terms the account bought, not the package as it stands', function () {
        /*
         * §8.3. An administrator lowering a staff limit must not withdraw the
         * facility from everyone already paying for it — a change of terms is a
         * renewal or an upgrade, which an account accepts. It is not something
         * that happens to them between one request and the next.
         */
        $account = testAccountWithStaffLimit(5);
        $package = Package::query()->latest('id')->firstOrFail();

        /*
         * Capture the terms the way buying does. The shared fixture writes a
         * subscription without them, which falls back to the live package —
         * deliberate, for rows written before snapshots existed (P1-32), but
         * the opposite of what this test is about.
         */
        $subscription = $account->currentPackage;
        $subscription->forceFill([
            'terms' => SubscriptionTerms::capture($package)->toArray(),
            'terms_captured_at' => now(),
        ])->save();

        app(ManagePackages::class)->update(
            $package,
            [],
            [PackageFeature::StaffLimit->value => '0'],
            [],
            testPlatformStaff(PlatformRole::SuperAdmin),
        );

        $this->actingAs($account->owner->refresh())
            ->get(route('staff.index'))
            ->assertOk();
    });

    it('reads the effective subscription, not just the pointer column', function () {
        /*
         * `current_user_package_id` is written at activation and nothing
         * rewrites it when a term ends. An account whose pointer leads to a
         * finished term but which holds a live one must not be told it has
         * nothing — the newest entitling subscription is the effective one.
         */
        $account = testAccountWithStaffLimit(5);

        $account->forceFill(['current_user_package_id' => null])->save();

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertOk();
    });
});

describe('what the navigation is told', function () {
    it('withholds the entry the gate would refuse', function () {
        /*
         * The sidebar and the settings nav both read `account.managesStaff`,
         * and the gate reads the same allowance underneath it. A control that
         * appears and then 403s is worse than one that never appeared, and a
         * control that appears while the URL is open is worse still.
         */
        $account = testAccountWithStaffLimit(0);

        $this->actingAs($account->owner)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.managesStaff', false)
                ->where('account.allowsStaff', false),
            );
    });

    it('offers it when the package pays for it', function () {
        $account = testAccountWithStaffLimit(5);

        $this->actingAs($account->owner)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.managesStaff', true)
                ->where('account.allowsStaff', true),
            );
    });
});

describe('who the gate must never touch', function () {
    it('lets platform staff into administration without a package', function () {
        /*
         * D23. A Feriwala staff member has no business account, and a gate that
         * asked them for a package would lock them out of their own back office.
         */
        $admin = testPlatformStaff(PlatformRole::KycManager);

        $this->actingAs($admin)
            ->get(route('admin.kyc.index'))
            ->assertOk();
    });

    it('is on no administrative route', function () {
        // Asserted structurally rather than screen by screen: a future admin
        // route that picked this middleware up would be a lockout nobody sees
        // until a staff member without a package tries to work.
        $offenders = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
            ->filter(fn ($route) => collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => str_starts_with((string) $middleware, 'entitled')))
            ->map(fn ($route) => $route->getName())
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });

    it('leaves the recovery and payment paths open to an account with nothing', function () {
        /*
         * §5.4. These are how an account *becomes* entitled. Gating them on
         * being entitled is a door that locks from the inside — and the account
         * that most needs them is exactly the one with no package.
         */
        $account = testAccountWithStaffLimit(0);

        foreach (['onboarding.status', 'packages.index', 'subscription.show', 'profile.edit', 'kyc.history'] as $name) {
            $this->actingAs($account->owner)
                ->get(route($name))
                ->assertOk();
        }
    });
});
