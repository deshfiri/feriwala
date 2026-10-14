<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An erased account stays, as an anonymous tombstone.
 *
 * The ledger, orders, payments and audit trail refer to people and accounts by
 * id, and must go on balancing and reading whole after someone is erased — so
 * the row is kept and everything that identified the person is replaced. These
 * two columns say that has happened and give the tombstone a stable reference to
 * be known by (`ERASED-` and a short id), which is all that is left of the name.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['users', 'suppliers', 'business_accounts'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('erased_at')->nullable();
                $table->string('tombstone_reference', 32)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropUnique(['tombstone_reference']);
                $table->dropColumn(['erased_at', 'tombstone_reference']);
            });
        }
    }
};
