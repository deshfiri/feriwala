<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Http\Middleware\EnsureAccountIsActivated;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->onboarding = User::factory()->create([
        'status' => AccountStatus::KycPending,
        'email_verified_at' => now(),
        'mobile_verified_at' => now(),
    ]);

    $this->active = User::factory()->create([
        'status' => AccountStatus::Active,
        'activated_at' => now(),
        'email_verified_at' => now(),
        'mobile_verified_at' => now(),
    ]);
});

describe('what an unactivated account may reach (§5.4)', function () {
    it('lets them into the areas that are built', function () {
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

    it('turns them away from everything else', function () {
        $this->actingAs($this->onboarding)
            ->get(route('teams.index'))
            ->assertRedirect(route('onboarding.status'));
    });

    it('redirects rather than refusing', function () {
        // The user has done nothing wrong; they have steps left. A 403 says the
        // opposite, and gives them nowhere to go.
        $this->actingAs($this->onboarding)
            ->get(route('teams.index'))
            ->assertStatus(302);
    });

    it('lets an active account through', function () {
        $this->actingAs($this->active)
            ->get(route('teams.index'))
            ->assertOk();
    });
});

describe('the allow-list itself', function () {
    it('has no prefix that matches nothing', function () {
        // A prefix matching no route is not harmless — it is a §5.4 area the
        // user silently cannot reach. `payment.` sat here for exactly that
        // reason while the real routes were named `checkout.`.
        //
        // Fortify and passkey routes are registered by packages, so a prefix
        // owned by one of those is checked against the full route list too.
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();

        $matches = fn (string $prefix) => $names->contains(
            fn (string $name) => str_starts_with($name, $prefix),
        );

        $dead = collect(EnsureAccountIsActivated::ALLOWED_ROUTE_PREFIXES)
            ->diff(EnsureAccountIsActivated::PENDING_ROUTE_PREFIXES)
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

        $built = collect(EnsureAccountIsActivated::PENDING_ROUTE_PREFIXES)
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
        Route::middleware(['web', 'auth', 'activated'])
            ->get('_test/unlisted', fn () => 'reached')
            ->name('unlisted.route');

        $this->actingAs($this->onboarding)
            ->get('/_test/unlisted')
            ->assertRedirect(route('onboarding.status'));
    });

    it('does not let an unactivated account work an admin queue', function () {
        // Administration is deliberately absent from the allow-list, so a
        // suspended staff member loses the panel with everything else.
        $suspended = User::factory()->create([
            'status' => AccountStatus::Suspended,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($suspended)
            ->get(route('admin.kyc.index'))
            ->assertRedirect(route('onboarding.status'));
    });
});
