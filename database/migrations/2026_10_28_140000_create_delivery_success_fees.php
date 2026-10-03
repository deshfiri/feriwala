<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Delivery Success Fee charged against a supplier's own payable, the
 * moment its order reaches Delivered (D-new).
 *
 * A decision row, not a workflow: every column is known at the moment it is
 * written — the base (the payable's own `gross_amount`), the rate in force,
 * frozen into the row so a later change to the configured percentage never
 * rewrites a fee already charged, and the resulting ledger entry it posted.
 * Nothing about it ever changes afterwards, so — unlike `referral_commissions`,
 * which still has status and payment columns left to move — this table is
 * locked against every update, not just a subset of columns, the same way
 * `order_items` is.
 *
 * One fee per payable, ever: `supplier_payable_id` is unique, which is what
 * makes charging it idempotent against a retried delivery transition, a
 * replayed webhook, or a repeated admin action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_success_fees', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payable_id')->unique()->constrained()->restrictOnDelete();

            $table->decimal('base_amount', 19, 2);
            $table->char('currency_code', 3);
            $table->decimal('rate_percent', 6, 2);
            $table->decimal('fee_amount', 19, 2);

            $table->foreignId('supplier_ledger_entry_id')->constrained()->restrictOnDelete();

            $table->timestamp('created_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE delivery_success_fees
                ADD CONSTRAINT delivery_success_fees_amounts_not_negative CHECK (
                    base_amount >= 0 AND rate_percent >= 0 AND fee_amount >= 0
                ),
                ADD CONSTRAINT delivery_success_fees_rate_within_bounds CHECK (rate_percent <= 100);

            CREATE OR REPLACE FUNCTION feriwala_delivery_success_fee_is_a_decision() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'delivery_success_fees is the financial record of a charge already made: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER delivery_success_fees_are_decisions
                BEFORE UPDATE OR DELETE ON delivery_success_fees
                FOR EACH ROW EXECUTE FUNCTION feriwala_delivery_success_fee_is_a_decision();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS delivery_success_fees_are_decisions ON delivery_success_fees;
            DROP FUNCTION IF EXISTS feriwala_delivery_success_fee_is_a_decision();
        SQL);

        Schema::dropIfExists('delivery_success_fees');
    }
};
