<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `2026_09_01_130000_extend_users_for_accounts` added `public_id` as
 * nullable -- correctly, since the column was joining a table that already
 * held rows -- but never backfilled those existing rows with a generated
 * ULID. `App\Concerns\HasPublicId` only ever sets one on Eloquent's
 * `creating` event, so any row from before that migration (or from any
 * other insert path that bypasses it) is stuck with `public_id = null`
 * forever.
 *
 * That is not a cosmetic gap: every screen that links to a user by public
 * id (Wayfinder's generated route helpers do `'public_id' in args` before
 * falling back to a bare string) throws `TypeError: Cannot use 'in'
 * operator to search for 'public_id' in null` the moment such a row
 * reaches a `<DataTable>` row -- crashing the whole table with no error
 * boundary, which is exactly what made the Platform Staff screen render
 * blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('public_id')
            ->select('id')
            ->orderBy('id')
            ->get()
            ->each(fn (object $row) => DB::table('users')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::ulid()]));

        Schema::table('users', function (Blueprint $table) {
            $table->ulid('public_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->change();
        });
    }
};
