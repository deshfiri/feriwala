<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock changed by hand (§19, P3-24).
 *
 * "Only the Admin or an Authorized User can directly modify Central stock."
 * Every such change is a decision a person made, so it is recorded as one: what
 * kind of change, how many units, why, and who — with the reason and the person
 * required by the database, not merely encouraged by a form — linked one-to-one
 * to the movement it made in the stock ledger.
 *
 * Append-only. A wrong adjustment is answered by another adjustment, never by
 * editing the first, so the record of what somebody decided survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();

            // The movement this adjustment made, and only that one.
            $table->foreignId('stock_movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();

            $table->string('kind', 40);
            $table->integer('quantity');
            $table->text('reason');

            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['stock_item_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_adjustments
                ADD CONSTRAINT stock_adjustments_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT stock_adjustments_kind_known CHECK (kind IN (
                    'receive', 'remove', 'damage', 'repair', 'write_off_damaged',
                    'restock_return', 'reject_return'
                )),
                -- A change nobody explained is the first thing an auditor asks about.
                ADD CONSTRAINT stock_adjustments_reason_given CHECK (char_length(btrim(reason)) >= 10);

            CREATE TRIGGER stock_adjustments_no_update
                BEFORE UPDATE ON stock_adjustments
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER stock_adjustments_no_delete
                BEFORE DELETE ON stock_adjustments
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_adjustments_no_update ON stock_adjustments;
            DROP TRIGGER IF EXISTS stock_adjustments_no_delete ON stock_adjustments;
        SQL);

        Schema::dropIfExists('stock_adjustments');
    }
};
