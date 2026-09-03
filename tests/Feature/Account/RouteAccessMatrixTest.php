<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Who can reach what, stated once as a table (§5.4, §32).
 *
 * The individual gate tests each prove one rule. This proves the *shape* of the
 * system: four kinds of route against every account state that a gate turns on.
 * A change that opens a route to a suspended account, or closes one to somebody
 * mid-onboarding who needs it, shows up here as a single flipped cell rather
 * than as an absence nobody notices.
 *
 * Every account is built with an explicitly named factory state. The default
 * factory produces an activated account, and a default that quietly satisfies
 * the gate under test is how a broken gate passes its own suite.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * The four kinds of route, one representative each.
 *
 * @return array<string, string>
 */
function matrixRoutes(): array
{
    return [
        // Reachable without signing in at all.
        'public' => route('home'),

        // A §5.4 area: open throughout onboarding.
        'onboarding' => route('onboarding.status'),

        // Business ERP: staff management, gated on activation.
        'business' => route('teams.index'),

        // Administration: gated on a platform permission.
        'admin' => route('admin.kyc.index'),
    ];
}

/**
 * What each route did for this user: 'ok', 'funnel' (sent back to onboarding),
 * 'denied', or 'guest' (sent to login).
 *
 * @return array<string, string>
 */
function accessMatrixFor(?User $user): array
{
    $result = [];

    foreach (matrixRoutes() as $kind => $url) {
        $response = $user === null
            ? test()->get($url)
            : test()->actingAs($user)->get($url);

        $location = $response->headers->get('Location');

        $result[$kind] = match (true) {
            $response->getStatusCode() === 403 => 'denied',
            $location === route('login') => 'guest',
            $location === route('onboarding.status') => 'funnel',
            $response->getStatusCode() < 400 => 'ok',
            default => 'error:'.$response->getStatusCode(),
        };
    }

    return $result;
}

it('lets a guest see only the public site', function () {
    expect(accessMatrixFor(null))->toBe([
        'public' => 'ok',
        'onboarding' => 'guest',
        'business' => 'guest',
        'admin' => 'guest',
    ]);
});

it('keeps an onboarding account in the funnel', function () {
    // Mid-registration: may finish setting up, may not trade or administer.
    expect(accessMatrixFor(User::factory()->onboarding(AccountStatus::KycPending)->create()))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'funnel',
        ]);
});

it('keeps an account waiting for approval in the funnel', function () {
    // Everything done and waiting on us. Still not trading — approval is the
    // last gate, and until it is given nothing commercial opens.
    expect(accessMatrixFor(User::factory()->approvalPending()->create()))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'funnel',
        ]);
});

it('opens the business ERP to an active account', function () {
    expect(accessMatrixFor(User::factory()->active()->create()))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'ok',

            // Active but holding no platform role: the permission refuses,
            // which is a different answer from the funnel's redirect.
            'admin' => 'denied',
        ]);
});

it('sends an account asked for corrections back to the funnel', function () {
    expect(accessMatrixFor(User::factory()->kycResubmissionRequired()->create()))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'funnel',
        ]);
});

it('closes everything to a suspended account', function () {
    expect(accessMatrixFor(User::factory()->suspended()->create()))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'funnel',
        ]);
});

it('closes everything to a closed account', function () {
    expect(accessMatrixFor(User::factory()->closed()->create()))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'funnel',
        ]);
});

it('opens administration to a platform role', function () {
    $reviewer = User::factory()->staff()->create();
    $reviewer->assignRole(PlatformRole::KycManager->value);

    expect(accessMatrixFor($reviewer))->toBe([
        'public' => 'ok',
        'onboarding' => 'ok',
        'business' => 'ok',
        'admin' => 'ok',
    ]);
});

it('takes administration away from a suspended platform role', function () {
    // A permission is not a licence that survives suspension.
    $reviewer = User::factory()->suspended()->create();
    $reviewer->assignRole(PlatformRole::KycManager->value);

    expect(accessMatrixFor($reviewer)['admin'])->not->toBe('ok');
});
