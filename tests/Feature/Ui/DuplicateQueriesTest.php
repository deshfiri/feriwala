<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The same query, with the same values, run twice in one request is work thrown
 * away. These pages are the ones every person lands on; none of them may repeat
 * a query.
 */

function duplicateQueriesOn(callable $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $request();

    $seen = [];

    foreach (DB::getQueryLog() as $query) {
        $key = $query['query'].'|'.json_encode($query['bindings']);
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }

    DB::disableQueryLog();

    return collect($seen)->filter(fn (int $count) => $count > 1)
        ->map(fn (int $count, string $key) => $count.'x '.substr($key, 0, 160))
        ->values()
        ->all();
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('runs no query twice when a Partner opens the dashboard', function () {
    $account = testBusinessAccount(AccountStatus::Active);

    $duplicates = duplicateQueriesOn(fn () => $this->actingAs($account->owner)->get(route('dashboard'))->assertOk());

    expect($duplicates)->toBe([]);
});

it('runs no query twice when staff open the admin dashboard', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $duplicates = duplicateQueriesOn(fn () => $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk());

    expect($duplicates)->toBe([]);
});
