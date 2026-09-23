<?php

use App\Domain\Inventory\ReservationWindows;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An Admin-set withdrawal minimum/maximum for one Supplier (D25, P13-24).
 *
 * The global default lives in ERP settings, the same bounded-setting pattern
 * {@see ReservationWindows} already uses. This adds
 * only the override, and only to `suppliers` — no other account type gains
 * a column, and no shared rule-resolution system gains a new scope, because
 * this batch's mandate was to change nothing outside the Supplier module.
 * Null means "use the default"; only an Admin sets either column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->bigInteger('withdrawal_minimum_override_minor')->nullable()->after('payout_details');
            $table->bigInteger('withdrawal_maximum_override_minor')->nullable()->after('withdrawal_minimum_override_minor');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_withdrawal_overrides_not_negative CHECK (
                    (withdrawal_minimum_override_minor IS NULL OR withdrawal_minimum_override_minor >= 0)
                    AND (withdrawal_maximum_override_minor IS NULL OR withdrawal_maximum_override_minor >= 0)
                ),
                ADD CONSTRAINT suppliers_withdrawal_override_range CHECK (
                    withdrawal_minimum_override_minor IS NULL
                    OR withdrawal_maximum_override_minor IS NULL
                    OR withdrawal_minimum_override_minor <= withdrawal_maximum_override_minor
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE suppliers
                DROP CONSTRAINT IF EXISTS suppliers_withdrawal_override_range,
                DROP CONSTRAINT IF EXISTS suppliers_withdrawal_overrides_not_negative;
        SQL);

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_minimum_override_minor', 'withdrawal_maximum_override_minor']);
        });
    }
};
