<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\VerifyContactManually;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The staff workspace for one Client/Partner account or Supplier: contact
 * verification standing, what staff may do about it, and nothing secret.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

it('shows a partner\'s contact standing and what the viewer may do', function () {
    $account = testBusinessAccount();
    $account->owner->forceFill(['mobile' => '+8801712345678', 'email_verified_at' => now(), 'mobile_verified_at' => null])->save();
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    app(VerifyContactManually::class)->handle($staff, $account->owner->fresh(), 'mobile', 'Confirmed by phone call.');

    $this->actingAs($staff)->get(route('admin.accounts.show', $account))
        ->assertInertia(fn (Assert $page) => $page->component('admin/accounts/show')
            ->where('contact.email.verified', true)
            ->where('contact.email.by_staff', false)
            ->where('contact.mobile.verified', true)
            ->where('contact.mobile.by_staff', true)
            ->where('contact.mobile.verified_by', $staff->name)
            ->where('contact.mobile.reason', 'Confirmed by phone call.')
            ->where('can.verify_contact', true)
            ->where('can.manage_identity', true));
});

it('gives view-only staff the standing but not the actions', function () {
    $account = testBusinessAccount();

    $this->actingAs(testPlatformStaff(PlatformRole::KycManager))->get(route('admin.accounts.show', $account))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.verify_contact', false)
            ->where('can.manage_identity', false)
            ->has('contact'));
});

it('never puts a password hash, a setup token or a secret into the workspace payload', function () {
    $account = testBusinessAccount();
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->actingAs($staff)->post(route('admin.accounts.setup-link.store', $account), ['reason' => 'Sending a link.']);

    $page = $this->get(route('admin.accounts.show', $account));
    $json = json_encode($page->viewData('page'));

    expect($json)->not->toContain($account->owner->password)
        ->not->toContain('remember_token')
        ->not->toContain('two_factor_secret');
});

it('shows a Supplier\'s standing and actions to Supplier managers only', function () {
    $supplier = Supplier::factory()->verificationPending()->create();

    $this->actingAs(testPlatformStaff(PlatformRole::SupplierManager))->get(route('admin.suppliers.show', $supplier))
        ->assertInertia(fn (Assert $page) => $page->component('admin/suppliers/show')
            ->where('contact.email.verified', false)
            ->where('supplier.can_verify_contact', true)
            ->where('supplier.can_manage_setup', true));

    $this->actingAs(testPlatformStaff(PlatformRole::Admin))->get(route('admin.suppliers.show', $supplier))
        ->assertInertia(fn (Assert $page) => $page
            ->where('supplier.can_verify_contact', false)
            ->where('supplier.can_manage_setup', false));
});

it('shows the verification and setup controls only where the navigation says staff may use them', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::Admin))->get(route('admin.accounts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['account.create'] === true
            && $permissions['supplier.create'] === false));

    $this->actingAs(testPlatformStaff(PlatformRole::SupplierManager))->get(route('admin.suppliers.index'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['supplier.create'] === true
            && $permissions['account.create'] === false));
});
