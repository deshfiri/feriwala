<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps how an ERP wholesale order was charged (D34).
 *
 * New orders are always paid in full. Orders placed under the earlier
 * delivery-only rule keep a NULL here and are deliberately not backfilled: their
 * snapshots and settlement behaviour must stay exactly as they were stored, and
 * a guess from the account's current type would rewrite them.
 *
 * Column-locked by its own trigger, alongside the older guards rather than by
 * editing them, as `account_type` was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('wholesale_payment_model', 16)->nullable()->after('account_type');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_wholesale_payment_model_known
                CHECK (wholesale_payment_model IS NULL OR wholesale_payment_model IN ('full'));

            CREATE TRIGGER orders_wholesale_payment_model_locked
                BEFORE UPDATE OF wholesale_payment_model ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('wholesale_payment_model');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_wholesale_payment_model_locked ON orders;');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('wholesale_payment_model');
        });
    }
};
