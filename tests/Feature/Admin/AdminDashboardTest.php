<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * The Admin/Staff portal's home screen.
 *
 * Every card is a plain count gated on the same permission its target screen
 * already requires (§ shared navigation registry / RBAC correction), so this
 * mirrors the same "never shows a door the viewer could not otherwise open"
 * invariant the navigation tests cover for the sidebar.
 */
it('redirects a guest to the login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('counts only the KYC submissions still awaiting a decision', function () {
    $account = testBusinessAccount(AccountStatus::KycPending);

    KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Submitted, 'round' => 1]);
    KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::UnderReview, 'round' => 2]);
    KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 3]);

    $staff = testPlatformStaff(PlatformRole::KycManager);

    $this->actingAs($staff)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('cards.0.key', 'kyc')
            ->where('cards.0.value', 2),
        );
});

it('shows no card for a permission the staff member does not hold', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('cards', []),
        );
});

it('shows every card to a super admin', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($staff)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->has('cards', 5),
        );
});
