<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
 * The gates that stand in front of administration (§36, §32.2), rebuilt for a
 * client with no session: a second factor for the sensitive roles, a password
 * sent with each sensitive action, and the web's default-reason behaviour.
 */

beforeEach(function () {
    // Platform roles are database rows; `testPlatformStaff()` assigns them.
    $this->seed(RolesAndPermissionsSeeder::class);

    Route::middleware(['api', 'auth:sanctum', 'mobile.access:user', 'mobile.two-factor', 'mobile.reason'])
        ->prefix('api/mobile/v1/admin')
        ->name('mobile.v1.admin.')
        ->group(function () {
            Route::get('_gates/read', fn () => ['ok' => true])->name('gates.read');
            Route::post('_gates/reason', fn (Illuminate\Http\Request $request) => ['reason' => $request->input('reason')])
                ->name('gates.reason');
            Route::post('roles/_probe', fn (Illuminate\Http\Request $request) => ['reason' => $request->input('reason')])
                ->name('roles.probe');
            Route::delete('_gates/sensitive', fn () => ['done' => true])
                ->middleware('mobile.password')
                ->name('gates.sensitive');
        });
});

test('a role that needs two-factor is refused until it has set it up', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $staff->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    Sanctum::actingAs($staff);

    $this->getJson('api/mobile/v1/admin/_gates/read')
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');
});

test('a role with two-factor set up may read', function () {
    Sanctum::actingAs(testPlatformStaff(PlatformRole::SuperAdmin));

    $this->getJson('api/mobile/v1/admin/_gates/read')->assertOk();
});

test('a reason is filled in on a staff screen where it is not a security matter', function () {
    Sanctum::actingAs(testPlatformStaff(PlatformRole::SuperAdmin));

    $this->postJson('api/mobile/v1/admin/_gates/reason')
        ->assertOk()
        ->assertJsonPath('reason', __('reasons.not_given'));

    $this->postJson('api/mobile/v1/admin/_gates/reason', ['reason' => 'Because'])
        ->assertJsonPath('reason', 'Because');
});

test('a reason is never filled in where the web demands one', function () {
    Sanctum::actingAs(testPlatformStaff(PlatformRole::SuperAdmin));

    // `admin.roles.*` is in config/reasons.php `required_for`.
    $this->postJson('api/mobile/v1/admin/roles/_probe')->assertOk()->assertJsonPath('reason', null);
});

test('a sensitive action without a password is locked', function () {
    Sanctum::actingAs(testPlatformStaff(PlatformRole::SuperAdmin));

    $this->deleteJson('api/mobile/v1/admin/_gates/sensitive')
        ->assertStatus(423)
        ->assertJsonPath('code', 'password_required');
});

test('a sensitive action with the right password goes through, with a wrong one it does not', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    Sanctum::actingAs($staff);

    $this->deleteJson('api/mobile/v1/admin/_gates/sensitive', ['current_password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $this->deleteJson('api/mobile/v1/admin/_gates/sensitive', ['current_password' => 'password'])
        ->assertOk();

    RateLimiter::clear('mobile-confirm-password:'.$staff->getAuthIdentifier());
});

test('guessing the password through a sensitive action is limited', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    Sanctum::actingAs($staff);

    foreach (range(1, 5) as $attempt) {
        $this->deleteJson('api/mobile/v1/admin/_gates/sensitive', ['current_password' => 'nope'])->assertUnprocessable();
    }

    $this->deleteJson('api/mobile/v1/admin/_gates/sensitive', ['current_password' => 'password'])->assertStatus(429);

    RateLimiter::clear('mobile-confirm-password:'.$staff->getAuthIdentifier());
});

test('a supplier token cannot reach the admin panel', function () {
    Sanctum::actingAs(App\Domain\Supplier\Models\Supplier::factory()->create());

    $this->getJson('api/mobile/v1/admin/_gates/read')
        ->assertForbidden()
        ->assertJsonPath('code', 'wrong_audience');
});

test('a suspended staff login is turned away', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $staff->forceFill(['identity_status' => App\Domain\Account\Enums\UserStatus::Suspended])->save();
    Sanctum::actingAs($staff);

    $this->getJson('api/mobile/v1/admin/_gates/read')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_unavailable');
});
