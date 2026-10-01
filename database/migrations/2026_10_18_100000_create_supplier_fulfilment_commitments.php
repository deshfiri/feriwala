<?php

use App\Domain\Order\Models\OrderItemAllocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Supplier's commitment to fulfil one order line from an on_demand or
 * pre_order offer -- no physical stock backs it, so this row **is** the
 * concurrency-safe capacity reservation the batch's own spec requires
 * (Supplier Bulk Product Listing batch, correction 7).
 *
 * One row per {@see OrderItemAllocation} (the unique
 * index below), created only when {@see
 * \App\Domain\Order\Actions\AllocateOrderLineSource} resolves a non-ready-stock
 * offer -- a ready_stock allocation never gets one, exactly as it never gets a
 * fake zero-stock row (correction 6). Capacity itself (`fulfilment_capacity`
 * on `supplier_offers`) is enforced in the application, by locking the offer
 * and summing this table's own non-terminal `quantity` -- a per-row CHECK
 * cannot see its siblings, so the guard belongs to {@see
 * \App\Domain\Supplier\Actions\RecordSupplierFulfilmentCommitment}, not to the
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_fulfilment_commitments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('order_item_allocation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_offer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');

            $table->string('status', 24);
            $table->timestamp('due_at')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->text('failed_reason')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancelled_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['supplier_offer_id', 'status']);
        });

        Schema::create('supplier_fulfilment_commitment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_fulfilment_commitment_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['supplier_fulfilment_commitment_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_fulfilment_commitments
                ADD CONSTRAINT supplier_fulfilment_commitments_status_known CHECK (
                    status IN ('awaiting_confirmation', 'confirmed', 'preparing', 'ready', 'failed', 'cancelled')
                ),
                ADD CONSTRAINT supplier_fulfilment_commitments_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT supplier_fulfilment_commitments_failure_is_recorded CHECK (
                    status <> 'failed' OR (failed_at IS NOT NULL AND failed_reason IS NOT NULL)
                ),
                ADD CONSTRAINT supplier_fulfilment_commitments_cancellation_is_recorded CHECK (
                    status <> 'cancelled' OR cancelled_at IS NOT NULL
                );

            CREATE TRIGGER supplier_fulfilment_commitments_locked_columns
                BEFORE UPDATE OF public_id, reference, order_item_allocation_id, supplier_offer_id, quantity
                ON supplier_fulfilment_commitments
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'order_item_allocation_id', 'supplier_offer_id', 'quantity'
                );

            CREATE OR REPLACE FUNCTION feriwala_supplier_fulfilment_commitment_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a fulfilment commitment is never deleted: it is confirmed, advanced, failed or cancelled'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_fulfilment_commitments_never_deleted
                BEFORE DELETE ON supplier_fulfilment_commitments
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_fulfilment_commitment_never_deleted();

            ALTER TABLE supplier_fulfilment_commitment_status_history
                ADD CONSTRAINT supplier_fulfilment_commitment_status_history_source_known CHECK (
                    source IN ('supplier', 'staff', 'system')
                ),
                ADD CONSTRAINT supplier_fulfilment_commitment_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER supplier_fulfilment_commitment_status_history_no_update
                BEFORE UPDATE ON supplier_fulfilment_commitment_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER supplier_fulfilment_commitment_status_history_no_delete
                BEFORE DELETE ON supplier_fulfilment_commitment_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_fulfilment_commitment_status_history_no_delete ON supplier_fulfilment_commitment_status_history;
            DROP TRIGGER IF EXISTS supplier_fulfilment_commitment_status_history_no_update ON supplier_fulfilment_commitment_status_history;
            DROP TRIGGER IF EXISTS supplier_fulfilment_commitments_never_deleted ON supplier_fulfilment_commitments;
            DROP FUNCTION IF EXISTS feriwala_supplier_fulfilment_commitment_never_deleted();
            DROP TRIGGER IF EXISTS supplier_fulfilment_commitments_locked_columns ON supplier_fulfilment_commitments;
        SQL);

        Schema::dropIfExists('supplier_fulfilment_commitment_status_history');
        Schema::dropIfExists('supplier_fulfilment_commitments');
    }
};
