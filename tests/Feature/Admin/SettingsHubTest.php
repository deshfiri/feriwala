<?php

use App\Domain\Access\Enums\PlatformRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The permission-filtered settings hub (commit-order item 4).
 *
 * A navigational wrapper: every card comes from a permission the target
 * screen already requires, so this mirrors the same "never shows a door the
 * viewer could not otherwise open" invariant {@see AdminDashboardTest}
 * covers for the dashboard's cards.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('redirects a guest to the login page', function () {
    $this->get(route('admin.settings'))->assertRedirect(route('login'));
});

it('shows only the Money section, and only the items payment.view unlocks, to a Payment Manager', function () {
    $staff = testPlatformStaff(PlatformRole::PaymentManager);

    $this->actingAs($staff)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->has('sections', 1)
            ->where('sections.0.key', 'money')
            ->has('sections.0.items', 3)
            ->where('sections.0.items.0.key', 'billing_rules')
            ->where('sections.0.items.0.href', route('admin.billing.index'))
            ->where('sections.0.items.1.key', 'payment_gateways')
            ->where('sections.0.items.2.key', 'payments'),
        );
});

it('shows both wallet-and-withdrawal-gated items to a Withdrawal Approver, and nothing payment-view-only needs', function () {
    $staff = testPlatformStaff(PlatformRole::WithdrawalApprover);

    $this->actingAs($staff)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->has('sections', 1)
            ->where('sections.0.key', 'money')
            ->has('sections.0.items', 2)
            ->where('sections.0.items.0.key', 'deposit_rules')
            ->where('sections.0.items.1.key', 'withdrawal_limits')
            ->where('sections.0.items.1.href', route('admin.withdrawal-limits.index')),
        );
});

it('shows only the sms item, never branding, to an SMS Manager', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->has('sections', 1)
            ->where('sections.0.key', 'communication')
            ->has('sections.0.items', 1)
            ->where('sections.0.items.0.key', 'sms'),
        );
});

it('shows the storage item under its own integrations section to a System Administrator', function () {
    // System::ManageSettings is also granted to this role, so branding
    // (communication) shows too -- the assertion here is only that
    // integrations/storage exists and needs nothing else to appear.
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->has('sections', 2)
            ->where('sections.0.key', 'communication')
            ->has('sections.0.items', 1)
            ->where('sections.0.items.0.key', 'branding')
            ->where('sections.1.key', 'integrations')
            ->has('sections.1.items', 1)
            ->where('sections.1.items.0.key', 'storage')
            ->where('sections.1.items.0.href', route('admin.storage-settings.index')),
        );
});

it('shows an empty hub to a role holding none of the covered settings permissions', function () {
    $staff = testPlatformStaff(PlatformRole::SeoManager);

    $this->actingAs($staff)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->where('sections', []),
        );
});

it('shows every section and every item to a Super Admin', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($staff)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->has('sections', 4)
            ->has('sections.0.items', 5)
            ->has('sections.1.items', 4)
            ->has('sections.2.items', 2)
            ->where('sections.3.key', 'integrations')
            ->has('sections.3.items', 1)
            ->where('sections.3.items.0.key', 'storage')
            ->where('sections.3.items.0.href', route('admin.storage-settings.index')),
        );
});
