<?php

use App\Domain\Account\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the placing account's {@see AccountType} onto every order.
 *
 * A placement-time fact like every other column `orders_locked_columns`
 * already guards — later changing the account's type must never rewrite how
 * an order already placed was charged. Column-locked by its own trigger here
 * rather than by editing `orders_locked_columns`, so the original migration
 * that created it is never touched (matches the `orders_never_deleted`-style
 * pattern of adding new guards alongside old ones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('account_type', 20)
                ->default(AccountType::Conditional->value)
                ->after('business_account_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_account_type_known CHECK (account_type IN ('conditional', 'non_conditional'));

            CREATE TRIGGER orders_account_type_locked
                BEFORE UPDATE OF account_type ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('account_type');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_account_type_locked ON orders;');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('account_type');
        });
    }
};
