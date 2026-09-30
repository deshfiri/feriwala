<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * `2026_09_01_130000_extend_users_for_accounts` added `users.public_id` as
 * nullable (correctly, since it was joining a table that already held rows)
 * but never backfilled the rows already there. `App\Concerns\HasPublicId`
 * only ever sets it on Eloquent's `creating` event, so any row from before
 * that migration -- or from any future insert path that bypasses it -- was
 * stuck with `public_id = null` forever.
 *
 * That silently broke Wayfinder's generated route helpers: they detect a
 * bare id vs. a whole row by testing `'public_id' in args`, and
 * `typeof null === 'object'` in JavaScript, so a null public_id crashed
 * with `TypeError: Cannot use 'in' operator to search for 'public_id' in
 * null` the moment such a row reached a `<DataTable>` link -- exactly what
 * made the Platform Staff screen render blank.
 * `2026_10_15_100000_backfill_user_public_ids` backfilled every existing
 * row and tightened the column to NOT NULL. This guards that it stays
 * tightened.
 */
it('never allows a user row with a null public_id at the database level', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'No Public Id',
        'email' => 'no-public-id@example.test',
        'password' => bcrypt('password'),
        'public_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('leaves no existing user without a public_id', function () {
    User::factory()->count(3)->create();

    expect(User::whereNull('public_id')->exists())->toBeFalse();
});
