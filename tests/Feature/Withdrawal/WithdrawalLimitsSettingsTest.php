<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Domain\Withdrawal\AccountWithdrawalLimits;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The platform-default withdrawal limits screen, and the per-owner override
 * on either side (§27, D25) -- gated on `withdrawal.manage_settings`, which
 * no seeded role holds today, so every "can manage" scenario here goes
 * through Super Admin's own override.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
});

describe('the settings screen', function () {
    it('shows both defaults to Super Admin', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.withdrawal-limits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/withdrawal-limits')
                ->where('account.minimum', AccountWithdrawalLimits::DEFAULT_MINIMUM)
                ->where('supplier.minimum', SupplierWithdrawalLimits::DEFAULT_MINIMUM)
                ->where('can.manage', true));
    });

    it('is closed to a business user', function () {
        $account = testBusinessAccount();

        $this->actingAs($account->owner)
            ->get(route('admin.withdrawal-limits.index'))
            ->assertForbidden();
    });

    it('is read-only for somebody who may only view withdrawals', function () {
        $reader = User::factory()->staff()->withTwoFactor()->create();
        $reader->givePermissionTo('withdrawal.view');

        $this->actingAs($reader)
            ->get(route('admin.withdrawal-limits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.manage', false));
    });

    it('is closed outright to somebody without even view access', function () {
        $reader = User::factory()->staff()->withTwoFactor()->create();

        $this->actingAs($reader)
            ->get(route('admin.withdrawal-limits.index'))
            ->assertForbidden();
    });
});

describe('setting a default', function () {
    it('sets the Client/Partner default and records why', function () {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.withdrawal-limits.account.update'), [
                'minimum' => '500.00',
                'maximum' => '50000.00',
            ])
            ->assertRedirect();

        expect(AuditLog::query()
            ->where('action', 'account.withdrawal_limits_default_set')
            ->where('actor_id', $this->superAdmin->id)
            ->exists())->toBeTrue();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.withdrawal-limits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.minimum', '500.00')
                ->where('account.maximum', '50000.00'));
    });

    it('sets the Supplier default separately', function () {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.withdrawal-limits.supplier.update'), [
                'minimum' => '1000.00',
                'maximum' => null,
            ])
            ->assertRedirect();

        expect(AuditLog::query()
            ->where('action', 'supplier.withdrawal_limits_default_set')
            ->exists())->toBeTrue();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.withdrawal-limits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('supplier.minimum', '1000.00')
                ->where('supplier.maximum', null));
    });

    it('refuses a minimum above the maximum', function () {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.withdrawal-limits.account.update'), [
                'minimum' => '5000.00',
                'maximum' => '1000.00',
            ])
            ->assertSessionHasErrors('minimum');
    });

    it('refuses a change from somebody without the manage-settings permission', function () {
        $reader = User::factory()->staff()->withTwoFactor()->create();
        $reader->givePermissionTo('withdrawal.view');

        $this->actingAs($reader)
            ->put(route('admin.withdrawal-limits.account.update'), [
                'minimum' => '500.00',
            ])
            ->assertForbidden();
    });
});

describe('setting an override', function () {
    it('sets a Client/Partner account override by its public id', function () {
        $account = testBusinessAccount();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.withdrawal-limits.account.override'), [
                'owner' => $account->public_id,
                'minimum' => '750.00',
                'maximum' => '20000.00',
            ])
            ->assertRedirect();

        expect($account->fresh()->withdrawal_minimum_override)->toBe('750.00')
            ->and($account->fresh()->withdrawal_maximum_override)->toBe('20000.00')
            ->and(AuditLog::query()
                ->where('action', 'account.withdrawal_limit_override_set')
                ->where('auditable_type', BusinessAccount::class)
                ->where('auditable_id', $account->id)
                ->exists())->toBeTrue();
    });

    it('sets a Supplier override by its public id', function () {
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.withdrawal-limits.supplier.override'), [
                'owner' => $supplier->public_id,
                'minimum' => '300.00',
                'maximum' => null,
            ])
            ->assertRedirect();

        expect($supplier->fresh()->withdrawal_minimum_override)->toBe('300.00')
            ->and(AuditLog::query()
                ->where('action', 'supplier.withdrawal_limit_override_set')
                ->where('auditable_type', Supplier::class)
                ->where('auditable_id', $supplier->id)
                ->exists())->toBeTrue();
    });

    it('refuses an override for an owner that does not exist', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.withdrawal-limits.account.override'), [
                'owner' => 'not-a-real-public-id',
                'minimum' => '500.00',
            ])
            ->assertNotFound();
    });

    it('refuses an override minimum above its maximum', function () {
        $account = testBusinessAccount();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.withdrawal-limits.account.override'), [
                'owner' => $account->public_id,
                'minimum' => '9000.00',
                'maximum' => '1000.00',
            ])
            ->assertSessionHasErrors('minimum');

        expect($account->fresh()->withdrawal_minimum_override)->toBeNull();
    });
});
