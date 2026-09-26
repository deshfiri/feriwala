<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Who may see and administer a dedicated website (§16.3, §31.3).
 *
 * A partner never holds platform website administration, whatever has been
 * handed to them: the ordered `Gate::before` refuses `website.*` to a business
 * identity, exactly as it refuses the catalogue and platform order
 * administration (see WebsitePolicy's own doc comment). This mirrors
 * OrderPolicyTest's "never gives a partner platform order administration"
 * case, which is the one already-tested sibling of this same Gate::before
 * ordering rule.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->karim = testBusinessAccount(AccountStatus::Active);
    $this->rahim = testBusinessAccount(AccountStatus::Active);

    $this->karimsWebsite = Website::factory()->forAccount($this->karim)->active()->create();
    $this->rahimsWebsite = Website::factory()->forAccount($this->rahim)->active()->create();
});

it('never gives a partner platform website administration, even when it is handed to them', function () {
    $partner = tap($this->karim->owner, fn (User $owner) => $owner->givePermissionTo('website.view', 'website.edit'));

    expect($partner->can('website.view'))->toBeFalse()
        ->and($partner->can('viewAny', Website::class))->toBeFalse()
        ->and($partner->can('view', $this->rahimsWebsite))->toBeFalse()
        ->and($partner->can('administer', $this->karimsWebsite))->toBeFalse()
        // Their own storefront is still theirs to see.
        ->and($partner->can('view', $this->karimsWebsite))->toBeTrue();
});

it('shows staff who may view websites every website', function (PlatformRole $role) {
    $staff = testPlatformStaff($role);

    expect($staff->can('viewAny', Website::class))->toBeTrue()
        ->and($staff->can('view', $this->karimsWebsite))->toBeTrue()
        ->and($staff->can('view', $this->rahimsWebsite))->toBeTrue();
})->with([
    'an admin' => PlatformRole::Admin,
    'a super admin' => PlatformRole::SuperAdmin,
]);
