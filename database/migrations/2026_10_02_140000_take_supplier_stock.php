<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier availability: a source of quantity distinguishable from central
 * warehouse stock, owned by one Supplier's one offer (D25, §19-equivalent,
 * P13-17).
 *
 * **Not a second inventory system.** `stock_items`/`stock_movements` (§19)
 * remain the record of what is physically in a Feriwala warehouse and what a
 * reservation holds against it; this is a separate, smaller thing — what a
 * Supplier says they can supply against one offer, which is not warehouse
 * stock until fulfilment actually draws on it (a later phase's integration
 * point, not built in this batch). Keeping the two apart is exactly what
 * "preserve central stock and Supplier stock as distinguishable sources"
 * requires: nothing here writes to `stock_items`, and nothing in §19 writes
 * here.
 *
 * A Supplier may only ever **submit** an update; it takes effect only once
 * approved, so a Supplier cannot claim availability into existence
 * unilaterally. Every applied change — the approval, or a staff adjustment —
 * is one row in the append-only `supplier_stock_movements` ledger, mirroring
 * the shape `stock_movements` already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_offer_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_offer_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('supplier_stock_updates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_offer_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('requested_quantity');
            $table->string('status', 16);
            $table->text('note')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['supplier_offer_id', 'status']);
        });

        Schema::create('supplier_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_stock_update_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->string('source', 32);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();

            $table->timestamp('created_at');

            $table->index(['supplier_offer_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_stock_updates
                ADD CONSTRAINT supplier_stock_updates_status_known CHECK (
                    status IN ('pending', 'approved', 'rejected')
                );

            ALTER TABLE supplier_stock_movements
                ADD CONSTRAINT supplier_stock_movements_source_known CHECK (
                    source IN ('initial', 'supplier_update_approved', 'staff_adjustment')
                ),
                ADD CONSTRAINT supplier_stock_movements_actor_type_known CHECK (
                    actor_type IN ('supplier', 'staff', 'system')
                );

            -- Append-only: an applied change to a Supplier's availability is
            -- corrected by a new movement, never by rewriting the old one.
            CREATE OR REPLACE FUNCTION feriwala_supplier_stock_movements_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'supplier_stock_movements is append-only'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_stock_movements_no_update
                BEFORE UPDATE ON supplier_stock_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_stock_movements_immutable();

            CREATE TRIGGER supplier_stock_movements_no_delete
                BEFORE DELETE ON supplier_stock_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_stock_movements_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_stock_movements_no_delete ON supplier_stock_movements;
            DROP TRIGGER IF EXISTS supplier_stock_movements_no_update ON supplier_stock_movements;
            DROP FUNCTION IF EXISTS feriwala_supplier_stock_movements_immutable();
        SQL);

        Schema::dropIfExists('supplier_stock_movements');
        Schema::dropIfExists('supplier_stock_updates');
        Schema::dropIfExists('supplier_offer_stock');
    }
};
