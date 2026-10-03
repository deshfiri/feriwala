<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/*
 * The platform-wide grant never reaches a Client/Partner or Supplier session --
 * through Gate::before or any other route -- and a refused session gets a clean
 * refusal, not a server error.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('still gives Super Admin staff everything', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    expect(Gate::forUser($staff)->allows('create', ProductSourcingGroup::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('create', BusinessAccount::class))->toBeTrue();
});

it('never lets a Supplier identity pass any ability, and never errors on one', function () {
    $supplier = Supplier::factory()->create();

    expect(Gate::forUser($supplier)->allows('create', ProductSourcingGroup::class))->toBeFalse()
        ->and(Gate::forUser($supplier)->allows('create', BusinessAccount::class))->toBeFalse()
        ->and(Gate::forUser($supplier)->allows('anything.at.all'))->toBeFalse();
});

it('gives an ordinary Client/Partner owner no platform ability at all', function () {
    $owner = testBusinessAccount()->owner;

    expect(Gate::forUser($owner)->allows('create', ProductSourcingGroup::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', BusinessAccount::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('sourcing_group.create'))->toBeFalse();

    $this->actingAs($owner)->get(route('admin.sourcing-groups.index'))->assertForbidden();
    $this->get(route('admin.accounts.create'))->assertForbidden();
    $this->post(route('admin.suppliers.store'), [])->assertForbidden();
});

it('refuses every new account-management route to Client/Partner and Supplier sessions', function (string $method, string $route, array $params) {
    $account = testBusinessAccount();
    $supplier = Supplier::factory()->verificationPending()->create();
    $resolved = array_map(fn ($value) => match ($value) {
        'account' => $account,
        'supplier' => $supplier,
        default => $value,
    }, $params);

    // A Client/Partner owner.
    $this->actingAs($account->owner)->call($method, route($route, $resolved))->assertForbidden();

    // A Supplier, signed in on its own guard only (as a browser holds it).
    auth()->guard('web')->logout();
    supplierTestSignIn($supplier);
    $this->call($method, route($route, $resolved))->assertRedirect(route('login'));
})->with([
    'add partner screen' => ['GET', 'admin.accounts.create', []],
    'create partner' => ['POST', 'admin.accounts.store', []],
    'send partner link' => ['POST', 'admin.accounts.setup-link.store', ['account']],
    'revoke partner link' => ['DELETE', 'admin.accounts.setup-link.destroy', ['account']],
    'verify partner contact' => ['POST', 'admin.accounts.verify-contact', ['account']],
    'add supplier screen' => ['GET', 'admin.suppliers.create', []],
    'create supplier' => ['POST', 'admin.suppliers.store', []],
    'send supplier link' => ['POST', 'admin.suppliers.setup-link.store', ['supplier']],
    'verify supplier contact' => ['POST', 'admin.suppliers.verify-contact', ['supplier']],
    'sourcing groups' => ['GET', 'admin.sourcing-groups.index', []],
]);
