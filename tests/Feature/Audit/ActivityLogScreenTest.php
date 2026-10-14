<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The activity log screen (§36.2): read by an Admin and anybody granted
 * `audit.view_audit_logs`, and by nobody else.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = testPlatformStaff(PlatformRole::Admin);

    app(RecordAuditLog::class)->handle(new AuditEntry(
        action: 'inventory.stock_tracked',
        actorId: $this->admin->id,
        after: ['sku' => 'FW-KT'],
        module: 'inventory',
    ));
    app(RecordAuditLog::class)->handle(new AuditEntry(
        action: 'wallet.adjusted',
        actorId: $this->admin->id,
        reason: 'Correcting a duplicate credit',
        module: 'wallet',
    ));
});

describe('who may read the activity log', function () {
    it('shows it to an Admin and to a System Administrator', function (Closure $identity) {
        $this->actingAs($identity())
            ->get(route('admin.activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/activity-log/index')
                ->has('entries.data', 2)
                ->where('summary.today', 2)
                ->where('summary.people_today', 1)
                ->where('entries.data.0.action', 'wallet.adjusted')
                ->where('entries.data.0.actor', $this->admin->name));
    })->with([
        'an Admin' => fn () => testPlatformStaff(PlatformRole::Admin),
        'a System Administrator' => fn () => testPlatformStaff(PlatformRole::SystemAdministrator),
    ]);

    it('refuses staff without the permission and partners holding it', function (Closure $identity) {
        $this->actingAs($identity())
            ->get(route('admin.activity-log.index'))
            ->assertForbidden();
    })->with([
        'staff without the permission' => fn () => testPlatformStaff(PlatformRole::ProductManager),
        'a partner holding the permission' => fn () => tap(
            testBusinessAccount(AccountStatus::Active)->owner,
            fn (User $owner) => $owner->givePermissionTo('audit.view_audit_logs'),
        ),
    ]);
});

describe('opening the log for one person', function () {
    it('lists active staff and narrows the feed to the one chosen', function () {
        $other = testPlatformStaff(PlatformRole::SystemAdministrator);

        app(RecordAuditLog::class)->handle(new AuditEntry(
            action: 'access.role_changed',
            actorId: $other->id,
            module: 'access',
        ));
        app(RecordAuditLog::class)->handle(new AuditEntry(action: 'billing.expired', actorId: null, module: 'billing'));

        $this->actingAs($this->admin)
            ->get(route('admin.activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('people.staff', 2)
                ->where('people.staff.0.id', $other->public_id)
                ->where('people.system', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.activity-log.index', ['person' => $this->admin->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.actor', $this->admin->name));

        $this->actingAs($this->admin)
            ->get(route('admin.activity-log.index', ['person' => 'system']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'billing.expired'));
    });
});

describe('narrowing the log', function () {
    it('filters by area, search term and sensitivity', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.activity-log.index', ['module' => 'inventory']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'inventory.stock_tracked'));

        $this->actingAs($this->admin)
            ->get(route('admin.activity-log.index', ['search' => 'duplicate credit']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'wallet.adjusted'));

        // A malformed area is ignored rather than reaching a query.
        $this->actingAs($this->admin)
            ->get(route('admin.activity-log.index', ['module' => "x'; drop"]))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 2)->where('filters.module', null));
    });
});
