<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Actions\PurgeAuditLogs;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\AuditRetention;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Clearing the activity log (D29, carried as D33): the one guarded way an audit
 * entry is ever deleted, and since 2026-10-14 the Super Admin's alone. The log
 * stays append-only for everything else.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->admin = testPlatformStaff(PlatformRole::Admin);
    $this->other = testPlatformStaff(PlatformRole::SystemAdministrator);

    foreach ([[$this->admin, 'catalog.edited'], [$this->admin, 'wallet.adjusted'], [$this->other, 'access.role_changed']] as [$who, $action]) {
        app(RecordAuditLog::class)->handle(new AuditEntry(action: $action, actorId: $who->id, module: 'test'));
    }
    app(RecordAuditLog::class)->handle(new AuditEntry(action: 'billing.expired', actorId: null, module: 'billing'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function purgeAuditPayload(array $overrides = []): array
{
    return [
        'password' => 'password',
        'reason' => 'Housekeeping after the audit review',
        'scope' => 'everyone',
        'confirm' => '1',
        ...$overrides,
    ];
}

/**
 * An entry that already happened `$days` ago: inserted with its own timestamp,
 * since a recorded entry can never be updated.
 */
function purgeAuditOldEntry(string $action, int $days): void
{
    AuditLog::create(['action' => $action, 'actor_type' => 'system', 'created_at' => now()->subDays($days)]);
}

describe('clearing everything', function () {
    it('deletes every entry, and leaves a permanent record of the clearing', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.activity-log.purge'), purgeAuditPayload())
            ->assertRedirect(route('admin.activity-log.index'))
            ->assertSessionHasNoErrors();

        $remaining = AuditLog::query()->get();

        expect($remaining)->toHaveCount(1)
            ->and($remaining->first()->action)->toBe('audit.logs_purged')
            ->and($remaining->first()->actor_id)->toBe($this->superAdmin->id)
            ->and($remaining->first()->reason)->toBe('Housekeeping after the audit review')
            ->and($remaining->first()->before)->toBe(['deleted' => 4])
            ->and($remaining->first()->after['scope'])->toBe('everyone');
    });

    it('can never delete the record of a clearing, even inside a purge', function () {
        app(PurgeAuditLogs::class)->handle($this->superAdmin, null, null, 'Housekeeping after the audit review');

        expect(fn () => DB::transaction(function () {
            DB::statement("SELECT set_config('feriwala.purge_audit_logs', 'on', true)");
            DB::table('audit_logs')->where('action', 'audit.logs_purged')->delete();
        }))->toThrow(QueryException::class);

        expect(AuditLog::query()->where('action', 'audit.logs_purged')->count())->toBe(1);
    });

    it('is still refused as a plain delete, from anywhere else', function () {
        expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))->toThrow(QueryException::class)
            ->and(AuditLog::query()->count())->toBe(4);
    });
});

describe('clearing narrower', function () {
    it('clears only one staff member\'s entries', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.activity-log.purge'), purgeAuditPayload(['scope' => 'person', 'person' => $this->admin->public_id]))
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('actor_id', $this->admin->id)->where('action', '<>', 'audit.logs_purged')->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'access.role_changed')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'billing.expired')->count())->toBe(1);
    });

    it('clears only the system\'s entries', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.activity-log.purge'), purgeAuditPayload(['scope' => 'person', 'person' => 'system']))
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'billing.expired')->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'catalog.edited')->count())->toBe(1);
    });

    it('clears only entries older than the days given', function () {
        purgeAuditOldEntry('old.thing', 40);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.activity-log.purge'), purgeAuditPayload(['older_than_days' => 30]))
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'old.thing')->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'wallet.adjusted')->count())->toBe(1);
    });
});

describe('who may clear it, and on what terms', function () {
    it('refuses a wrong password, a short reason and a missing confirmation, and deletes nothing', function (array $override, string $field) {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.activity-log.purge'), purgeAuditPayload($override))
            ->assertSessionHasErrors($field);

        expect(AuditLog::query()->count())->toBe(4);
    })->with([
        'a wrong password' => [['password' => 'nope'], 'password'],
        'a short reason' => [['reason' => 'short'], 'reason'],
        'no confirmation' => [['confirm' => null], 'confirm'],
    ]);

    it('refuses staff without the permission and partners holding it', function (Closure $identity) {
        $this->actingAs($identity())
            ->post(route('admin.activity-log.purge'), purgeAuditPayload())
            ->assertForbidden();

        expect(AuditLog::query()->count())->toBe(4);
    })->with([
        'staff without the permission' => fn () => testPlatformStaff(PlatformRole::ProductManager),
        'a partner holding the permission' => fn () => tap(
            testBusinessAccount(AccountStatus::Active)->owner,
            fn (User $owner) => $owner->givePermissionTo('audit.delete'),
        ),
    ]);

    it('refuses an Admin, and staff whose role was given the permission, at the screen and in the action', function (Closure $identity) {
        $actor = $identity();

        $this->actingAs($actor)
            ->post(route('admin.activity-log.purge'), purgeAuditPayload())
            ->assertForbidden();

        expect(fn () => app(PurgeAuditLogs::class)->handle($actor, null, null, 'Housekeeping after the audit review'))
            ->toThrow(AuthorizationException::class)
            ->and(AuditLog::query()->count())->toBe(4);
    })->with([
        'an Admin' => fn () => test()->admin,
        'staff granted audit.delete' => fn () => tap(
            testPlatformStaff(PlatformRole::SystemAdministrator),
            fn (User $staff) => $staff->givePermissionTo('audit.delete'),
        ),
    ]);

    it('refuses a caller that skipped the controller', function () {
        expect(fn () => app(PurgeAuditLogs::class)->handle(testPlatformStaff(PlatformRole::ProductManager), null, null, 'Housekeeping after the audit review'))
            ->toThrow(AuthorizationException::class);

        expect(AuditLog::query()->count())->toBe(4);
    });

    it('shows the clear control only to those who may use it', function () {
        $this->actingAs($this->superAdmin)->get(route('admin.activity-log.index'))
            ->assertInertia(fn ($page) => $page->where('can.purge', true));

        $viewer = testPlatformStaff(PlatformRole::ProductManager);
        $viewer->givePermissionTo('audit.view_audit_logs');

        $this->actingAs($viewer)->get(route('admin.activity-log.index'))
            ->assertInertia(fn ($page) => $page->where('can.purge', false));

        $this->actingAs($this->admin)->get(route('admin.activity-log.index'))
            ->assertInertia(fn ($page) => $page->where('can.purge', false)->where('can.manage_retention', false));
    });
});

describe('automatic clearing after a number of days', function () {
    it('does nothing while retention is off', function () {
        expect(app(PurgeAuditLogs::class)->purgeExpired())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(4);
    });

    it('removes entries older than the setting, and records that it did', function () {
        purgeAuditOldEntry('old.thing', 40);

        app(SettingsRepository::class)->define(AuditRetention::SETTING, 'audit', SettingType::Integer, 0);
        app(SettingsRepository::class)->set(AuditRetention::SETTING, 30);

        expect(app(PurgeAuditLogs::class)->purgeExpired())->toBe(1)
            ->and(AuditLog::query()->where('action', 'old.thing')->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'wallet.adjusted')->count())->toBe(1);

        $record = AuditLog::query()->where('action', 'audit.logs_purged')->sole();

        expect($record->actor_type)->toBe('system')
            ->and($record->actor_label)->toBe('Retention policy')
            ->and($record->after['scope'])->toBe('retention');
    });
});

describe('the retention setting', function () {
    it('is changed by the Super Admin, audited, and refused to anybody else', function () {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.activity-log-retention.update'), ['days' => 90])
            ->assertSessionHasNoErrors();

        expect(app(AuditRetention::class)->days())->toBe(90)
            ->and(AuditLog::query()->where('action', 'audit.retention_changed')->exists())->toBeTrue();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.activity-log-retention.update'), ['days' => 0])
            ->assertSessionHasNoErrors();

        expect(app(AuditRetention::class)->days())->toBeNull();

        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->put(route('admin.activity-log-retention.update'), ['days' => 5])
            ->assertForbidden();

        // An Admin may read the log but not shorten how long it is kept.
        $this->actingAs($this->admin)
            ->put(route('admin.activity-log-retention.update'), ['days' => 5])
            ->assertForbidden();

        expect(app(AuditRetention::class)->days())->toBeNull();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.activity-log-retention.update'), ['days' => 99999])
            ->assertSessionHasErrors('days');
    });
});
