<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Models\Redirect;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Public-site redirects through the admin screen (§34.1, Stage 7). Loop and
 * chain refusal is ManageRedirect's own job (see CmsRedirectTest for the
 * exhaustive cases) — this only proves the controller surfaces that refusal
 * as a field error rather than a 500.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
});

it('creates a redirect', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.redirects.store'), [
            'from_path' => '/old',
            'to_path' => '/new',
            'status_code' => 301,
        ])
        ->assertRedirect();

    expect(Redirect::query()->where('from_path', '/old')->exists())->toBeTrue();
});

it('refuses a redirect that would chain into an existing one', function () {
    Redirect::query()->create(['from_path' => '/a', 'to_path' => '/b']);

    $this->actingAs($this->manager)
        ->post(route('admin.cms.redirects.store'), [
            'from_path' => '/c',
            'to_path' => '/a',
            'status_code' => 301,
        ])
        ->assertSessionHasErrors('to_path');
});

it('changes where an existing redirect points', function () {
    $redirect = Redirect::query()->create(['from_path' => '/old', 'to_path' => '/new']);

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.redirects.update', $redirect->public_id), [
            'to_path' => '/newer',
            'status_code' => 302,
        ])
        ->assertRedirect();

    expect($redirect->refresh()->to_path)->toBe('/newer')
        ->and($redirect->status_code)->toBe(302);
});

it('toggles a redirect on and off', function () {
    $redirect = Redirect::query()->create(['from_path' => '/old', 'to_path' => '/new']);

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.redirects.active', $redirect->public_id), ['is_enabled' => false])
        ->assertRedirect();

    expect($redirect->refresh()->is_enabled)->toBeFalse();
});

it('removes a redirect', function () {
    $redirect = Redirect::query()->create(['from_path' => '/old', 'to_path' => '/new']);

    $this->actingAs($this->manager)
        ->delete(route('admin.cms.redirects.destroy', $redirect->public_id))
        ->assertRedirect();

    expect(Redirect::query()->count())->toBe(0);
});
