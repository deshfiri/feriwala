<?php

use App\Domain\Order\Enums\OrderCourierStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The courier-neutral shipment domain (Advanced Order Management batch,
 * Commit 5; §18, §21, P6.C).
 *
 * `status` is a foreign key straight into the already-seeded
 * `order_courier_statuses` lookup table (from
 * `2026_10_19_120000_create_order_courier_lifecycle.php`) rather than a new
 * lookup of its own — a shipment's status **is**
 * {@see OrderCourierStatus}, per that enum's own
 * docblock ("driven directly by a Shipment's own status"). Reusing the table
 * means reusing its transition map too: the trigger below reads the existing
 * `order_courier_status_transitions` rows instead of seeding a duplicate
 * graph. `unassigned` is excluded by CHECK — that case means "no shipment
 * exists yet" at the order level and a `shipments` row is never created in
 * it.
 *
 * `order_id`, `courier_provider_id`, the delivery charge and its rule
 * snapshot are the decision this shipment was created with — locked the same
 * way `supplier_fulfilment_commitments` locks its own decision columns, so a
 * later rule-settings change (Commit 6) can never reach back and alter what a
 * shipment already froze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('courier_provider_id')->constrained()->restrictOnDelete();

            $table->string('status', 40);
            $table->string('tracking_number', 64)->nullable();

            $table->string('currency_code', 3);
            $table->decimal('delivery_charge', 19, 2);
            $table->json('delivery_charge_rule_snapshot')->nullable();
            $table->decimal('cod_amount', 19, 2)->nullable();
            $table->decimal('return_charge', 19, 2)->nullable();

            $table->timestamp('pickup_requested_at')->nullable();
            $table->string('label_path')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);

            $table->foreign('status')->references('code')->on('order_courier_statuses')->restrictOnDelete()->cascadeOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments
                ADD CONSTRAINT shipments_status_not_unassigned CHECK (status <> 'unassigned'),
                ADD CONSTRAINT shipments_delivery_charge_not_negative CHECK (delivery_charge >= 0),
                ADD CONSTRAINT shipments_cod_amount_not_negative CHECK (cod_amount IS NULL OR cod_amount >= 0),
                ADD CONSTRAINT shipments_return_charge_not_negative CHECK (return_charge IS NULL OR return_charge >= 0),
                ADD CONSTRAINT shipments_cancellation_is_recorded CHECK (
                    status <> 'cancelled' OR cancelled_at IS NOT NULL
                );

            CREATE TRIGGER shipments_locked_columns
                BEFORE UPDATE OF public_id, reference, order_id, courier_provider_id,
                    currency_code, delivery_charge, delivery_charge_rule_snapshot
                ON shipments
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'order_id', 'courier_provider_id',
                    'currency_code', 'delivery_charge', 'delivery_charge_rule_snapshot'
                );

            CREATE OR REPLACE FUNCTION feriwala_shipment_transition_is_allowed() RETURNS trigger AS $$
            BEGIN
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT EXISTS (
                    SELECT 1 FROM order_courier_status_transitions
                    WHERE from_status = OLD.status AND to_status = NEW.status
                ) THEN
                    RAISE EXCEPTION 'shipment % cannot move status from % to %', OLD.reference, OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER shipments_status_follows_transition_map
                BEFORE UPDATE OF status ON shipments
                FOR EACH ROW EXECUTE FUNCTION feriwala_shipment_transition_is_allowed();

            CREATE OR REPLACE FUNCTION feriwala_shipment_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a shipment is never deleted: it is advanced or cancelled'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER shipments_never_deleted
                BEFORE DELETE ON shipments
                FOR EACH ROW EXECUTE FUNCTION feriwala_shipment_never_deleted();
        SQL);

        Schema::create('shipment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreign('previous_status')->references('code')->on('order_courier_statuses')->restrictOnDelete();
            $table->foreign('new_status')->references('code')->on('order_courier_statuses')->restrictOnDelete();

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['shipment_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shipment_status_history
                ADD CONSTRAINT shipment_status_history_source_known CHECK (
                    source IN ('staff', 'system', 'webhook')
                ),
                ADD CONSTRAINT shipment_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER shipment_status_history_no_update
                BEFORE UPDATE ON shipment_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER shipment_status_history_no_delete
                BEFORE DELETE ON shipment_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_status_history');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shipments_never_deleted ON shipments;
            DROP FUNCTION IF EXISTS feriwala_shipment_never_deleted();
            DROP TRIGGER IF EXISTS shipments_status_follows_transition_map ON shipments;
            DROP FUNCTION IF EXISTS feriwala_shipment_transition_is_allowed();
            DROP TRIGGER IF EXISTS shipments_locked_columns ON shipments;
        SQL);

        Schema::dropIfExists('shipments');
    }
};
