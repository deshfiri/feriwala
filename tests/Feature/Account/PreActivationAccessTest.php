<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Http\Middleware\EnsureBusinessAccountIsActivated;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->onboarding = testBusinessAccount(AccountStatus::KycPending)->owner;
    $this->trading = testBusinessAccount(AccountStatus::Active)->owner;
});

describe('what an unactivated business may reach (§5.4)', function () {
    it('lets it into the areas that are built', function () {
        foreach ([
            'onboarding.status',
            'kyc.create',
            'packages.index',
            'checkout.show',
            'profile.edit',
        ] as $name) {
            // Not asserting 200 — a route may legitimately redirect for its own
            // reasons. What must not happen is being sent back to the funnel.
            $location = $this->actingAs($this->onboarding)
                ->get(route($name))
                ->headers->get('Location');

            expect($location)->not->toBe(
                route('onboarding.status'),
                "{$name} is a §5.4 area and must stay reachable before activation",
            );
        }
    });

    it('turns it away from everything else', function () {
        $this->actingAs($this->onboarding)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.status'));
    });

    it('redirects rather than refusing', function () {
        // The account holder has done nothing wrong; they have steps left. A 403
        // says the opposite, and gives them nowhere to go.
        $this->actingAs($this->onboarding)
            ->get(route('dashboard'))
            ->assertStatus(302);
    });

    it('lets an activated business through', function () {
        $this->actingAs($this->trading)
            ->get(route('dashboard'))
            ->assertOk();
    });
});

describe('the allow-list itself', function () {
    it('has no prefix that matches nothing', function () {
        // A prefix matching no route is not harmless — it is a §5.4 area the
        // user silently cannot reach. `payment.` sat here for exactly that
        // reason while the real routes were named `checkout.`.
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();

        $matches = fn (string $prefix) => $names->contains(
            fn (string $name) => str_starts_with($name, $prefix),
        );

        $dead = collect(EnsureBusinessAccountIsActivated::ALLOWED_ROUTE_PREFIXES)
            ->diff(EnsureBusinessAccountIsActivated::PENDING_ROUTE_PREFIXES)
            ->reject($matches)
            ->values()
            ->all();

        expect($dead)->toBe([]);
    });

    it('keeps the pending list honest', function () {
        // A prefix declared pending that now matches a route is a §5.4 area that
        // has been built. Moving it out of the pending list is how it stops
        // being invisible in the roadmap.
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();

        $built = collect(EnsureBusinessAccountIsActivated::PENDING_ROUTE_PREFIXES)
            ->filter(fn (string $prefix) => $names->contains(
                fn (string $name) => str_starts_with($name, $prefix),
            ))
            ->values()
            ->all();

        expect($built)->toBe([]);
    });

    it('closes an unlisted route by default', function () {
        // The direction that matters: a new ERP route nobody thought about is
        // shut, not open.
        Route::middleware(['web', 'auth', 'business.activated'])
            ->get('_test/unlisted', fn () => 'reached')
            ->name('unlisted.route');

        $this->actingAs($this->onboarding)
            ->get('/_test/unlisted')
            ->assertRedirect(route('onboarding.status'));
    });
});

describe('administration is not behind the commercial gate (D23)', function () {
    it('lets platform staff work a queue without owning a business', function () {
        // The point of the whole split: administering the platform takes an
        // identity and a permission, not commercial KYC and an activation fee.
        $reviewer = testPlatformStaff(PlatformRole::KycManager);

        expect($reviewer->businessAccount)->toBeNull();

        $this->actingAs($reviewer)
            ->get(route('admin.kyc.index'))
            ->assertOk();
    });

    it('still refuses staff who lack the permission', function () {
        // Not a bypass — the policy is what admits them, and it has not moved.
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('admin.kyc.index'))
            ->assertForbidden();
    });

    it('keeps a commercially suspended owner out of the ERP but not the panel', function () {
        // One person, two roles. Suspending their business takes the business
        // ERP; it does not take the admin panel, because that was never what
        // the business account governed.
        $account = testBusinessAccount(AccountStatus::Suspended);
        $reviewer = $account->owner;
        $reviewer->assignRole(PlatformRole::KycManager->value);

        // KYC Manager reads personal documents, so §36 requires a second
        // factor before the panel opens at all (P1-18). That gate is a
        // different question from this one and has to be satisfied first.
        $reviewer->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($reviewer)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.status'));

        $this->actingAs($reviewer)
            ->get(route('admin.kyc.index'))
            ->assertOk();
    });
});
