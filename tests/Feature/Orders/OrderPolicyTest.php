<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Who may see and act on an order (§18.4, §18.5, §31.3).
 *
 * An account sees its own orders and nobody else's; platform staff see and move
 * orders through the order permissions their role holds; and a person who
 * trades on the platform never gains platform order administration, whatever is
 * handed to them.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->karim = testBusinessAccount(AccountStatus::Active);
    $this->rahim = testBusinessAccount(AccountStatus::Active);

    $this->karimsOrder = Order::factory()->create(['business_account_id' => $this->karim->id]);
    $this->rahimsOrder = Order::factory()->create(['business_account_id' => $this->rahim->id]);
});

it('shows an account its own orders and nobody else\'s', function () {
    $owner = $this->karim->owner;
    $member = User::factory()->staffOf($this->karim, AccountRole::Staff)->create();

    foreach ([$owner, $member] as $person) {
        expect($person->can('view', $this->karimsOrder))->toBeTrue()
            ->and($person->can('view', $this->rahimsOrder))->toBeFalse()
            ->and($person->can('viewAny', Order::class))->toBeFalse();
    }
});

it('shows staff who may view orders every order', function (PlatformRole $role) {
    $staff = testPlatformStaff($role);

    expect($staff->can('viewAny', Order::class))->toBeTrue()
        ->and($staff->can('view', $this->karimsOrder))->toBeTrue()
        ->and($staff->can('view', $this->rahimsOrder))->toBeTrue();
})->with([
    'an order manager' => PlatformRole::OrderManager,
    'a fulfillment manager' => PlatformRole::FulfillmentManager,
    'a super admin' => PlatformRole::SuperAdmin,
]);

it('shows staff without the order permission no orders', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    expect($staff->can('viewAny', Order::class))->toBeFalse()
        ->and($staff->can('view', $this->karimsOrder))->toBeFalse();
});

it('never gives a partner platform order administration, even when it is handed to them', function () {
    $partner = tap($this->karim->owner, fn (User $owner) => $owner->givePermissionTo('order.view', 'order.edit'));

    expect($partner->can('order.view'))->toBeFalse()
        ->and($partner->can('viewAny', Order::class))->toBeFalse()
        ->and($partner->can('view', $this->rahimsOrder))->toBeFalse()
        ->and($partner->can('transition', $this->karimsOrder))->toBeFalse()
        // Their own order is still theirs to see.
        ->and($partner->can('view', $this->karimsOrder))->toBeTrue();
});

it('lets only staff who may edit orders move a status by hand', function (Closure $person, bool $allowed) {
    expect($person()->can('transition', $this->karimsOrder))->toBe($allowed);
})->with([
    'an order manager' => [fn () => testPlatformStaff(PlatformRole::OrderManager), true],
    'a report viewer' => [fn () => testPlatformStaff(PlatformRole::ReportViewer), false],
    'the account owner' => [fn () => test()->karim->owner, false],
]);

it('lets the account that placed an order, or staff who may edit orders, cancel it', function () {
    expect($this->karim->owner->can('cancel', $this->karimsOrder))->toBeTrue()
        ->and($this->rahim->owner->can('cancel', $this->karimsOrder))->toBeFalse()
        ->and(testPlatformStaff(PlatformRole::OrderManager)->can('cancel', $this->karimsOrder))->toBeTrue();
});
