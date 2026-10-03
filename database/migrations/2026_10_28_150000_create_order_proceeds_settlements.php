<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether, and how much, a Non-Conditional reseller earns on one order line
 * (D-new).
 *
 * **Two independent facts, not one.** `Delivered` and the COD cash actually
 * being collected by Banij are not the same financial event — mirrors the
 * exact two-fact shape `supplier_payables` already uses for its own
 * `delivered_at`/`payment_settled_at` gate. Reaching `Delivered` alone creates
 * this row with the expected figures and credits nothing; crediting the
 * reseller's wallet waits for `cod_collected_at` too, recorded by its own,
 * separate, explicit action. A declared resale amount the collection falls
 * short of is never silently zeroed: it is flagged for review instead.
 *
 * One row per order line, by unique index on `order_item_id` — what makes a
 * repeated delivery transition or a repeated collection-confirmation
 * idempotent.
 *
 * `resale_amount` and `recovered_amount` are frozen at the row's creation,
 * like every other snapshot in this application; the workflow columns that
 * follow — the two facts, the resulting earning, the review flag, and the
 * wallet transaction it posted — are the only ones that move, and only ever
 * from null to a value, once.
 */
return new class extends Migration
{
    private const LOCKED = [
        'public_id', 'order_id', 'order_item_id', 'business_account_id',
        'currency_code', 'resale_amount', 'recovered_amount', 'created_at',
    ];

    public function up(): void
    {
        Schema::create('order_proceeds_settlements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);

            // Frozen at placement: the reseller's own declared figure, and
            // what Banij recovers from it (the line's own total).
            $table->decimal('resale_amount', 19, 2);
            $table->decimal('recovered_amount', 19, 2);

            // The two independent facts.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cod_collected_at')->nullable();
            $table->decimal('cod_amount_collected', 19, 2)->nullable();

            // Set together, once, only when both facts above are in.
            $table->timestamp('eligible_at')->nullable();
            $table->decimal('reseller_earning', 19, 2)->nullable();
            $table->boolean('flagged_for_review')->default(false);
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();

            $table->timestamp('created_at');

            $table->index(['business_account_id', 'created_at']);
        });

        $locked = implode(', ', self::LOCKED);
        $lockedArguments = implode(', ', array_map(fn (string $column) => "'{$column}'", self::LOCKED));

        DB::unprepared(<<<SQL
            ALTER TABLE order_proceeds_settlements
                ADD CONSTRAINT order_proceeds_amounts_not_negative CHECK (
                    resale_amount >= 0 AND recovered_amount >= 0
                    AND (cod_amount_collected IS NULL OR cod_amount_collected >= 0)
                    AND (reseller_earning IS NULL OR reseller_earning >= 0)
                ),
                ADD CONSTRAINT order_proceeds_cod_collection_is_paired CHECK (
                    (cod_collected_at IS NULL) = (cod_amount_collected IS NULL)
                ),
                ADD CONSTRAINT order_proceeds_eligibility_needs_both_facts CHECK (
                    eligible_at IS NULL OR (delivered_at IS NOT NULL AND cod_collected_at IS NOT NULL)
                ),
                ADD CONSTRAINT order_proceeds_earning_set_with_eligibility CHECK (
                    (eligible_at IS NULL) = (reseller_earning IS NULL)
                ),
                ADD CONSTRAINT order_proceeds_wallet_credit_needs_eligibility CHECK (
                    wallet_transaction_id IS NULL OR eligible_at IS NOT NULL
                );

            CREATE TRIGGER order_proceeds_settlements_locked_columns
                BEFORE UPDATE OF {$locked} ON order_proceeds_settlements
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$lockedArguments});

            CREATE OR REPLACE FUNCTION feriwala_order_proceeds_settlement_is_kept() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'order_proceeds_settlements rows are the financial record and are never deleted'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_proceeds_settlements_kept
                BEFORE DELETE ON order_proceeds_settlements
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_proceeds_settlement_is_kept();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_proceeds_settlements_kept ON order_proceeds_settlements;
            DROP TRIGGER IF EXISTS order_proceeds_settlements_locked_columns ON order_proceeds_settlements;
            DROP FUNCTION IF EXISTS feriwala_order_proceeds_settlement_is_kept();
        SQL);

        Schema::dropIfExists('order_proceeds_settlements');
    }
};
