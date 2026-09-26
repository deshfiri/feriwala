<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Models\SeoSetting;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The global, per-locale SEO defaults (§34, Stage 7) — their own permission
 * set (`seo.*`), separate from ordinary page content.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seoManager = testPlatformStaff(PlatformRole::SeoManager);
});

it('refuses the SEO screen to staff holding no SEO permission at all', function () {
    // ContentManager deliberately does hold seo.view/seo.edit (it can see and
    // adjust page-level SEO context), so this needs a role with neither.
    $outsider = testPlatformStaff(PlatformRole::PackageManager);

    $this->actingAs($outsider)->get(route('admin.cms.seo.index'))
        ->assertForbidden();
});

it('saves the global default for a locale', function () {
    $this->actingAs($this->seoManager)
        ->patch(route('admin.cms.seo.update', 'en'), [
            'default_title' => 'Feriwala',
            'default_description' => 'Wholesale and dropshipping ERP.',
            'organization_name' => 'Feriwala',
            'robots_default' => 'index, follow',
        ])
        ->assertRedirect();

    $setting = SeoSetting::query()->where('locale', 'en')->firstOrFail();

    expect($setting->default_title)->toBe('Feriwala');
});

it('refuses an unsupported locale', function () {
    $this->actingAs($this->seoManager)
        ->patch(route('admin.cms.seo.update', 'fr'), [
            'default_title' => 'Feriwala',
            'organization_name' => 'Feriwala',
            'robots_default' => 'index, follow',
        ])
        ->assertSessionHasErrors('locale');
});
