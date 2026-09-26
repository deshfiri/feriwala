<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Order\Models\Order;
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

it('counts only the KYC submissions still awaiting a decision, and mirrors it in the attention total and breakdown', function () {
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
            ->where('cards.0.value', 2)
            ->where('attention', 2)
            ->where('breakdown.0.key', 'kyc')
            ->where('breakdown.0.value', 2)
            // A KYC manager holds no order.view, so no trend chart -- not an
            // empty one, an absent one, exactly like the missing card would be.
            ->where('trend', null),
        );
});

it('shows no card, no attention and no breakdown for a permission the staff member does not hold', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('cards', [])
            ->where('attention', 0)
            ->where('breakdown', [])
            ->where('trend', null),
        );
});

it('gives the order trend only to someone who holds order.view', function () {
    Order::factory()->count(3)->create(['created_at' => now()]);

    $staff = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($staff)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->has('trend.0.points', 14)
            ->where('trend.0.points.13.value', 3));
});

it('shows every card and the trend to a super admin', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($staff)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->has('cards', 5)
            ->has('breakdown', 5)
            ->has('trend.0.points', 14),
        );
});
