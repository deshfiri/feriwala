<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Who can reach what, stated once as a table (§5.4, §32, D23).
 *
 * The individual gate tests each prove one rule. This proves the *shape* of the
 * system: four kinds of route against every state that a gate turns on, on both
 * sides of the identity/business split. A change that opens a route to a
 * suspended login, or closes one to somebody mid-onboarding who needs it, shows
 * up here as a single flipped cell rather than as an absence nobody notices.
 *
 * Every subject is built with an explicitly named factory state. The factories'
 * defaults produce usable rows, and a default that quietly satisfies the gate
 * under test is how a broken gate passes its own suite.
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

        // Business ERP: gated on the account's activation, and on nothing
        // else — the staff screen would also answer to the package, which is a
        // different question from the one this matrix asks.
        'business' => route('dashboard'),

        // Administration: gated on identity plus a platform permission.
        'admin' => route('admin.kyc.index'),
    ];
}

/**
 * What each route did for this user: 'ok', 'funnel' (sent back to onboarding),
 * 'denied', or 'guest' (sent to login — which is also where a refused identity
 * lands, because the identity gate signs it out).
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

it('keeps an onboarding business in the funnel', function () {
    // Mid-registration: may finish setting up, may not trade.
    expect(accessMatrixFor(testBusinessAccount(AccountStatus::KycPending)->owner))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',

            // No platform role, so administration refuses on permission. That
            // is a different answer from the funnel's redirect, and the
            // difference is the point of D23.
            'admin' => 'denied',
        ]);
});

it('keeps a business waiting for approval in the funnel', function () {
    expect(accessMatrixFor(testBusinessAccount(AccountStatus::ApprovalPending)->owner))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'denied',
        ]);
});

it('opens the business ERP to an activated business', function () {
    expect(accessMatrixFor(testBusinessAccount(AccountStatus::Active)->owner))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'ok',
            'admin' => 'denied',
        ]);
});

it('sends a business asked for corrections back to the funnel', function () {
    expect(accessMatrixFor(testBusinessAccount(AccountStatus::KycResubmissionRequired)->owner))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'denied',
        ]);
});

it('closes the business ERP to a commercially suspended business', function () {
    // Suspending a business takes the ERP. It does not touch the login, which
    // is why the owner still reaches the onboarding page to read why.
    expect(accessMatrixFor(testBusinessAccount(AccountStatus::Suspended)->owner))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'denied',
        ]);
});

it('closes the business ERP to a closed business', function () {
    expect(accessMatrixFor(testBusinessAccount(AccountStatus::Closed)->owner))
        ->toBe([
            'public' => 'ok',
            'onboarding' => 'ok',
            'business' => 'funnel',
            'admin' => 'denied',
        ]);
});

it('opens administration to platform staff who own no business', function () {
    // The D23 case. No business account at all, so no commercial onboarding —
    // and the business ERP correctly turns them back, because it is not theirs.
    expect(accessMatrixFor(testPlatformStaff(PlatformRole::KycManager)))
        ->toBe([
            'public' => 'ok',

            // The onboarding page is a commercial screen and refuses them
            // outright: there is no application of theirs to show. Honest, and
            // different from the funnel's "come back when you are ready".
            'onboarding' => 'denied',

            'business' => 'funnel',
            'admin' => 'ok',
        ]);
});

it('takes everything from a suspended login, administration included', function () {
    // A permission is not a licence that survives suspension. The identity gate
    // signs them out, so every authenticated route answers as it would for a
    // stranger.
    $reviewer = User::factory()->identity(UserStatus::Suspended)->create();
    $reviewer->assignRole(PlatformRole::KycManager->value);

    expect(accessMatrixFor($reviewer))->toBe([
        'public' => 'ok',
        'onboarding' => 'guest',
        'business' => 'guest',
        'admin' => 'guest',
    ]);
});

it('takes everything from a locked login too', function () {
    $reviewer = User::factory()->locked()->create();
    $reviewer->assignRole(PlatformRole::KycManager->value);

    expect(accessMatrixFor($reviewer)['admin'])->toBe('guest');
});

it('separates the two suspensions', function () {
    // The distinction the whole split exists for: one person who both owns a
    // business and reviews KYC keeps the panel when their business is
    // suspended, and loses it when their login is.
    $account = testBusinessAccount(AccountStatus::Suspended);
    $reviewer = $account->owner;
    $reviewer->assignRole(PlatformRole::KycManager->value);

    expect(accessMatrixFor($reviewer)['admin'])->toBe('ok');

    $reviewer->forceFill(['identity_status' => UserStatus::Suspended])->save();

    expect(accessMatrixFor($reviewer->fresh())['admin'])->toBe('guest');
});
