<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Admin\DashboardAccessController;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * An authorised administrator opening a partner's or a Supplier's own
 * dashboard. The link is signed, expires and is bound to the administrator it
 * was made for; the route is still gated by policy on every request.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
});

describe('a partner', function () {
    it('is offered the link on the account screen', function () {
        $account = testBusinessAccount();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard_url', fn ($url) => str_contains($url, 'open-dashboard') && str_contains($url, 'signature='))
                ->where('can.complete_manually', false),
            );
    });

    it('opens the owner’s dashboard and remembers the way back', function () {
        $account = testBusinessAccount();
        $url = DashboardAccessController::partnerLink($account, $this->superAdmin);

        $this->actingAs($this->superAdmin)
            ->get($url)
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($account->owner);
        expect(session('impersonation.admin_id'))->toBe($this->superAdmin->id);
        expect(DB::table('audit_logs')->where('action', 'account.dashboard_opened')->where('actor_id', $this->superAdmin->id)->exists())->toBeTrue();
    });

    it('puts the administrator back when the visit ends', function () {
        $account = testBusinessAccount();

        $this->actingAs($account->owner)
            ->withSession([
                'impersonation.admin_id' => $this->superAdmin->id,
                'impersonation.account' => $account->public_id,
            ])
            ->post(route('impersonation.stop'))
            ->assertRedirect(route('admin.accounts.show', $account->public_id));

        $this->assertAuthenticatedAs($this->superAdmin);
        expect(session()->has('impersonation.admin_id'))->toBeFalse();
    });

    it('cannot be ended by someone who is not in a visit', function () {
        $account = testBusinessAccount();

        $this->actingAs($account->owner)
            ->post(route('impersonation.stop'))
            ->assertForbidden();
    });

    it('does not work from an unsigned or altered link', function () {
        $account = testBusinessAccount();
        $url = DashboardAccessController::partnerLink($account, $this->superAdmin);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.accounts.open-dashboard', $account->public_id))
            ->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->get($url.'0')
            ->assertForbidden();
    });

    it('does not work for anyone but the administrator it was made for', function () {
        $account = testBusinessAccount();
        $other = testPlatformStaff(PlatformRole::Admin);
        $url = DashboardAccessController::partnerLink($account, $this->superAdmin);

        $this->actingAs($other)->get($url)->assertForbidden();
    });

    it('is refused to staff without the sensitive-data permission', function () {
        $account = testBusinessAccount();
        $staff = testPlatformStaff(PlatformRole::ContentManager);

        expect($staff->can('account.view_sensitive_data'))->toBeFalse();

        $this->actingAs($staff)
            ->get(DashboardAccessController::partnerLink($account, $staff))
            ->assertForbidden();

        $this->assertAuthenticatedAs($staff);
    });
});

describe('a supplier', function () {
    it('opens the portal beside the administrator’s own session', function () {
        $supplier = Supplier::factory()->create();
        $url = DashboardAccessController::supplierLink($supplier, $this->superAdmin);

        $this->actingAs($this->superAdmin)
            ->get($url)
            ->assertRedirect(route('supplier.dashboard'));

        // Two guards, two identities: the administrator is not swapped out.
        $this->assertAuthenticatedAs($this->superAdmin, 'web');
        $this->assertAuthenticatedAs($supplier, 'supplier');
        expect(DB::table('audit_logs')->where('action', 'supplier.dashboard_opened')->exists())->toBeTrue();
    });

    it('is refused to staff without the sensitive-data permission', function () {
        $supplier = Supplier::factory()->create();
        $staff = testPlatformStaff(PlatformRole::ContentManager);

        $this->actingAs($staff)
            ->get(DashboardAccessController::supplierLink($supplier, $staff))
            ->assertForbidden();

        $this->assertGuest('supplier');
    });

    it('ends the visit without touching the administrator', function () {
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->superAdmin)
            ->actingAs($supplier, 'supplier')
            ->withSession([
                'impersonation.supplier_by' => $this->superAdmin->id,
                'impersonation.supplier' => $supplier->public_id,
            ])
            ->post(route('impersonation.stop'))
            ->assertRedirect(route('admin.suppliers.show', $supplier->public_id));

        $this->assertGuest('supplier');
        $this->assertAuthenticatedAs($this->superAdmin, 'web');
    });
});
