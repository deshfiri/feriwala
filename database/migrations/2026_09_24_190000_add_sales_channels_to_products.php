<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dropshipping and wholesale, switched on per product (§11.1, §11.2).
 *
 * Two columns rather than two booleans, holding §11.2's own channel statuses —
 * "Dropshipping Enabled", "Wholesale Disabled" — so the vocabulary of the
 * specification is what is stored, and each column's CHECK admits only its own
 * two values.
 *
 * **Both start disabled.** A product is offered on a channel because somebody
 * decided it should be, never because the column defaulted that way; §12 names
 * "be enabled for Dropshipping" as a condition, not an assumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('dropshipping_status', 30)->default('dropshipping_disabled')->after('account_scope');
            $table->string('wholesale_status', 30)->default('wholesale_disabled')->after('dropshipping_status');

            $table->index(['status', 'dropshipping_status']);
            $table->index(['status', 'wholesale_status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_dropshipping_status_known
                    CHECK (dropshipping_status IN ('dropshipping_enabled', 'dropshipping_disabled')),
                ADD CONSTRAINT products_wholesale_status_known
                    CHECK (wholesale_status IN ('wholesale_enabled', 'wholesale_disabled'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_dropshipping_status_known,
                DROP CONSTRAINT IF EXISTS products_wholesale_status_known;
        SQL);

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status', 'dropshipping_status']);
            $table->dropIndex(['status', 'wholesale_status']);
            $table->dropColumn(['dropshipping_status', 'wholesale_status']);
        });
    }
};
