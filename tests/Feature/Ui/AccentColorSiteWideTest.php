<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Settings\AccentColor;
use App\Domain\Settings\Actions\ManageBranding;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The accent colour chosen under Admin → Branding reaches every surface of the
 * site — the public home page, both sign-in pages, and the ERP, Admin and
 * Supplier panels — painted on <html> before first load and shared with the
 * client for later visits. One setting, every shell: a page that forgot it
 * would open in the default colour.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    app(ManageBranding::class)->setAccent(
        testPlatformStaff(PlatformRole::SuperAdmin),
        AccentColor::fromHex('#059669'),
    );
});

/**
 * The page's opening `<html>` tag, where the site-wide accent is painted.
 */
function accentSiteWideRootTag(TestResponse $response): string
{
    preg_match('/<html\b[^>]*>/', (string) $response->getContent(), $matches);

    return $matches[0] ?? '';
}

it('paints the chosen accent on every kind of page', function (TestResponse $response) {
    $accent = AccentColor::fromHex('#059669');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('branding.accent.color', '#059669')
        ->where('branding.accent.on', $accent->onColor())
        ->where('branding.accent.lifted_on', $accent->onLiftedColor()),
    );

    expect(accentSiteWideRootTag($response))
        ->toContain('--brand-base: #059669;')
        ->toContain('--brand-on: '.$accent->onColor().';')
        ->toContain('--brand-lifted-on: '.$accent->onLiftedColor().';');
})->with([
    'the public home page' => fn () => $this->get(route('home')),
    'the sign-in page' => fn () => $this->get(route('login')),
    'the supplier sign-in page' => fn () => $this->get(route('supplier.login')),
    'the ERP panel' => fn () => $this
        ->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
        ->get(route('dashboard')),
    'the Admin panel' => fn () => $this
        ->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->get(route('admin.dashboard')),
    'the Supplier panel' => function () {
        supplierTestSignIn(Supplier::factory()->create());

        return $this->get(route('supplier.dashboard'));
    },
]);

it('stops painting it everywhere once the default is restored', function () {
    app(ManageBranding::class)->setAccent(testPlatformStaff(PlatformRole::SuperAdmin), null);

    foreach ([route('home'), route('login'), route('supplier.login')] as $url) {
        $response = $this->get($url)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->where('branding.accent', null));
        expect(accentSiteWideRootTag($response))->not->toContain('--brand-base');
    }
});
