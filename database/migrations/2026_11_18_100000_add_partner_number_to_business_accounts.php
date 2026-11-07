<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table): void {
            // `BJ` and eleven digits. Nullable because accounts that joined before
            // the number existed have none until `partners:assign-numbers` runs;
            // Postgres lets any number of rows stay null under a unique index.
            $table->string('partner_number', 13)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->dropUnique(['partner_number']);
            $table->dropColumn('partner_number');
        });
    }
};
