<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branded courier shipping labels.
 *
 * **Warehouses** gain the details a courier needs to bring a parcel back: an
 * approved contact, a phone, a complete return address, and an explicit switch
 * saying the warehouse may appear on labels. The switch cannot be on while any
 * of the required parts is blank -- an incomplete return address is never
 * printed by accident.
 *
 * **Shipments** gain the Banij warehouse the parcel left from, resolved from the
 * order's stock allocation or reservation and fixed once known, and the safe code
 * of why a label could not be issued yet.
 *
 * **Shipment labels** are the delivery snapshot frozen when the consignment was
 * confirmed: append-only, versioned per shipment, one current version at a time.
 * A correction is a new version; the version it replaces keeps every column and
 * only gains when and why it was superseded. Both print sizes render from these
 * columns -- no rendered HTML is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('courier_contact_name', 120)->nullable()->after('address');
            $table->string('courier_phone', 32)->nullable()->after('courier_contact_name');
            $table->string('return_address_line', 255)->nullable()->after('courier_phone');
            $table->string('return_area', 120)->nullable()->after('return_address_line');
            $table->string('return_district', 120)->nullable()->after('return_area');
            $table->string('return_postcode', 20)->nullable()->after('return_district');
            $table->boolean('usable_for_courier_labels')->default(false)->after('return_postcode');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warehouses
                ADD CONSTRAINT warehouses_courier_label_details_complete CHECK (
                    NOT usable_for_courier_labels OR (
                        btrim(coalesce(courier_contact_name, '')) <> ''
                        AND btrim(coalesce(courier_phone, '')) <> ''
                        AND btrim(coalesce(return_address_line, '')) <> ''
                        AND btrim(coalesce(return_district, '')) <> ''
                    )
                );
        SQL);

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('courier_provider_id')->constrained()->restrictOnDelete();
            $table->string('label_blocker', 48)->nullable()->after('label_path');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_shipment_warehouse_set_once() RETURNS trigger AS $$
            BEGIN
                IF OLD.warehouse_id IS NOT NULL AND NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id THEN
                    RAISE EXCEPTION 'shipment % already left warehouse %; it cannot be changed', OLD.reference, OLD.warehouse_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER shipments_warehouse_set_once
                BEFORE UPDATE OF warehouse_id ON shipments
                FOR EACH ROW EXECUTE FUNCTION feriwala_shipment_warehouse_set_once();
        SQL);

        Schema::create('shipment_labels', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');

            // The courier and the identifier the barcode encodes.
            $table->string('courier_provider_code', 32);
            $table->string('courier_provider_name', 80);
            $table->string('consignment_id', 64)->nullable();
            $table->string('tracking_code', 64)->nullable();
            $table->string('barcode_value', 64);

            // Customer-facing branding: the Storefront the customer bought from.
            $table->string('store_name', 160);
            $table->string('store_phone', 32)->nullable();
            $table->string('store_accent_color', 16)->nullable();
            $table->string('store_logo_disk', 32)->nullable();
            $table->string('store_logo_path')->nullable();
            $table->string('store_logo_mime', 64)->nullable();

            // Delivery.
            $table->string('recipient_name', 160);
            $table->string('recipient_phone', 32);
            $table->json('delivery_address');
            $table->text('delivery_instructions')->nullable();
            $table->string('currency_code', 3);
            $table->decimal('cod_amount', 19, 2);
            $table->boolean('is_prepaid');
            $table->unsignedSmallInteger('parcel_number');
            $table->unsignedSmallInteger('parcel_count');
            $table->unsignedSmallInteger('package_count');
            // Null when it cannot be stated exactly: two parcels from one warehouse.
            $table->unsignedInteger('item_quantity')->nullable();
            $table->string('order_reference', 32);
            $table->string('storefront_order_reference', 120)->nullable();
            $table->timestamp('order_placed_at');

            // Fulfilment and return: the Banij warehouse the parcel left from.
            $table->string('warehouse_code', 20);
            $table->string('warehouse_name', 120);
            $table->string('warehouse_contact_name', 120);
            $table->string('warehouse_phone', 32);
            $table->json('warehouse_return_address');

            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->text('issue_reason')->nullable();

            $table->timestamp('superseded_at')->nullable();
            $table->text('superseded_reason')->nullable();
            // Its foreign key is added below, deferred: a correction marks the old
            // version superseded before the one-current index admits the new one.
            $table->unsignedBigInteger('superseded_by_label_id')->nullable();

            $table->unique(['shipment_id', 'version']);
            $table->index(['order_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shipment_labels
                ADD CONSTRAINT shipment_labels_superseded_by_label_id_foreign
                    FOREIGN KEY (superseded_by_label_id) REFERENCES shipment_labels (id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                ADD CONSTRAINT shipment_labels_version_positive CHECK (version >= 1),
                ADD CONSTRAINT shipment_labels_cod_not_negative CHECK (cod_amount >= 0),
                ADD CONSTRAINT shipment_labels_prepaid_owes_nothing CHECK (NOT is_prepaid OR cod_amount = 0),
                ADD CONSTRAINT shipment_labels_parcel_in_range CHECK (parcel_number >= 1 AND parcel_number <= parcel_count),
                ADD CONSTRAINT shipment_labels_barcode_present CHECK (btrim(barcode_value) <> ''),
                ADD CONSTRAINT shipment_labels_correction_has_reason CHECK (version = 1 OR btrim(coalesce(issue_reason, '')) <> ''),
                ADD CONSTRAINT shipment_labels_supersession_complete CHECK (
                    (superseded_at IS NULL AND superseded_reason IS NULL AND superseded_by_label_id IS NULL)
                    OR (superseded_at IS NOT NULL AND btrim(coalesce(superseded_reason, '')) <> '' AND superseded_by_label_id IS NOT NULL)
                );

            -- One current label per shipment: a retry finds it rather than making another.
            CREATE UNIQUE INDEX shipment_labels_one_current_per_shipment
                ON shipment_labels (shipment_id)
                WHERE superseded_at IS NULL;

            -- An issued label is never rewritten. The only change it ever takes is
            -- being superseded, once, by a newer version.
            CREATE OR REPLACE FUNCTION feriwala_shipment_label_is_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a shipment label is never deleted; a correction is a new version'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF OLD.superseded_at IS NOT NULL
                    OR (to_jsonb(NEW) - 'superseded_at' - 'superseded_reason' - 'superseded_by_label_id')
                        IS DISTINCT FROM (to_jsonb(OLD) - 'superseded_at' - 'superseded_reason' - 'superseded_by_label_id') THEN
                    RAISE EXCEPTION 'shipment label % version % is frozen', OLD.shipment_id, OLD.version
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER shipment_labels_no_update
                BEFORE UPDATE ON shipment_labels
                FOR EACH ROW EXECUTE FUNCTION feriwala_shipment_label_is_append_only();

            CREATE TRIGGER shipment_labels_no_delete
                BEFORE DELETE ON shipment_labels
                FOR EACH ROW EXECUTE FUNCTION feriwala_shipment_label_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shipment_labels_no_delete ON shipment_labels;
            DROP TRIGGER IF EXISTS shipment_labels_no_update ON shipment_labels;
            DROP FUNCTION IF EXISTS feriwala_shipment_label_is_append_only();
        SQL);

        Schema::dropIfExists('shipment_labels');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shipments_warehouse_set_once ON shipments;
            DROP FUNCTION IF EXISTS feriwala_shipment_warehouse_set_once();
        SQL);

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('label_blocker');
        });

        DB::unprepared('ALTER TABLE warehouses DROP CONSTRAINT IF EXISTS warehouses_courier_label_details_complete');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'courier_contact_name', 'courier_phone', 'return_address_line',
                'return_area', 'return_district', 'return_postcode', 'usable_for_courier_labels',
            ]);
        });
    }
};
