<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Settings\AccentColor;
use App\Domain\Settings\Actions\ManageBranding;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The accent colour chosen under Admin → Branding reaches every surface of the
 * site — the public home page, both sign-in pages, and the ERP, Admin and
 * Supplier panels — painted on <html> before first load and shared with the
 * client for later visits. One setting, every shell: a page that forgot it
 * would open in the old colour.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    app(ManageBranding::class)->setAccent(
        testPlatformStaff(PlatformRole::SuperAdmin),
        AccentColor::fromHex('#059669'),
    );
});

it('paints the chosen accent on every kind of page', function (Closure $visit) {
    $response = $visit($this)->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('branding.accent.color', '#059669')
        ->where('branding.accent.on', AccentColor::fromHex('#059669')->onColor())
        ->where('branding.accent.lifted_on', AccentColor::fromHex('#059669')->onLiftedColor()),
    );

    expect((string) $response->getContent())
        ->toContain('--brand-base: #059669;')
        ->toContain('--brand-on: '.AccentColor::fromHex('#059669')->onColor().';');
})->with([
    'the public home page' => fn (TestCase $test) => $test->get(route('home')),
    'the sign-in page' => fn (TestCase $test) => $test->get(route('login')),
    'the supplier sign-in page' => fn (TestCase $test) => $test->get(route('supplier.login')),
    'the ERP panel' => fn (TestCase $test) => $test
        ->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
        ->get(route('dashboard')),
    'the Admin panel' => fn (TestCase $test) => $test
        ->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->get(route('admin.dashboard')),
    'the Supplier panel' => function (TestCase $test) {
        supplierTestSignIn(Supplier::factory()->create());

        return $test->get(route('supplier.dashboard'));
    },
]);

it('stops painting it everywhere once the default is restored', function () {
    app(ManageBranding::class)->setAccent(testPlatformStaff(PlatformRole::SuperAdmin), null);

    foreach ([route('home'), route('login'), route('supplier.login')] as $url) {
        $response = $this->get($url)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->where('branding.accent', null));
        expect((string) $response->getContent())->not->toContain('--brand-base');
    }
});
