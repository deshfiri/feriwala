<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more parts of a label, frozen with the rest when it is issued.
 *
 * **Items**: what is in the parcel, line by line -- name, variation, SKU and
 * quantity. Null when that cannot be stated exactly (a parcel that is one of
 * several), and on every label issued before this column existed: those reprint
 * exactly as they were, with their unit count only.
 *
 * **Parcel weight**: the sum of the shipment's recorded package weights, in kg,
 * only when every package has one. Never estimated from the catalogue.
 *
 * Adding columns is not an update to any row, so the append-only trigger is
 * untouched and every issued label keeps its content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_labels', function (Blueprint $table) {
            $table->json('items')->nullable()->after('item_quantity');
            $table->decimal('parcel_weight_kg', 8, 3)->nullable()->after('items');
        });

        DB::unprepared('ALTER TABLE shipment_labels ADD CONSTRAINT shipment_labels_weight_positive CHECK (parcel_weight_kg IS NULL OR parcel_weight_kg > 0)');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE shipment_labels DROP CONSTRAINT IF EXISTS shipment_labels_weight_positive');

        Schema::table('shipment_labels', function (Blueprint $table) {
            $table->dropColumn(['items', 'parcel_weight_kg']);
        });
    }
};
