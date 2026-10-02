<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical logistics and packaging data for a product (beta-critical batch,
 * Commit 1).
 *
 * One canonical unit per measurement, server-authoritative: weight in whole
 * grams, every dimension in centimetres, every quantity an integer piece
 * count. A UI may accept kilograms where that reads more naturally, but the
 * conversion happens before this boundary and the stored value is always
 * grams -- nothing here or downstream (the delivery-charge calculator, a
 * Shipment's own computed snapshot) ever has to guess which unit a number is
 * in.
 *
 * Every column is nullable: logistics data is not required to exist before a
 * product can, and a product with stock but no weight yet recorded is not an
 * error state -- it is simply a product the delivery-charge calculator
 * cannot yet price precisely (see `App\Domain\Billing\CalculateDeliveryCharge`
 * for how a missing figure is handled there). This is ordinary, continuously
 * editable catalogue data, not a decision or a snapshot, so unlike a
 * `shipments` row nothing here is locked by trigger -- staff correcting a
 * mismeasured weight is exactly the behaviour wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('net_weight_grams')->nullable();
            $table->unsignedInteger('shipping_weight_grams')->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();

            // Whether this product ships as loose individual units or packed
            // into boxes of a fixed count -- the box_* figures below only mean
            // anything when this is true.
            $table->boolean('ships_by_box')->default(false);
            $table->unsignedInteger('pieces_per_box')->nullable();
            $table->unsignedInteger('box_weight_grams')->nullable();
            $table->decimal('box_length_cm', 8, 2)->nullable();
            $table->decimal('box_width_cm', 8, 2)->nullable();
            $table->decimal('box_height_cm', 8, 2)->nullable();

            $table->boolean('is_fragile')->default(false);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_net_weight_positive CHECK (net_weight_grams IS NULL OR net_weight_grams > 0),
                ADD CONSTRAINT products_shipping_weight_positive CHECK (shipping_weight_grams IS NULL OR shipping_weight_grams > 0),
                ADD CONSTRAINT products_length_positive CHECK (length_cm IS NULL OR length_cm > 0),
                ADD CONSTRAINT products_width_positive CHECK (width_cm IS NULL OR width_cm > 0),
                ADD CONSTRAINT products_height_positive CHECK (height_cm IS NULL OR height_cm > 0),
                ADD CONSTRAINT products_pieces_per_box_positive CHECK (pieces_per_box IS NULL OR pieces_per_box > 0),
                ADD CONSTRAINT products_box_weight_positive CHECK (box_weight_grams IS NULL OR box_weight_grams > 0),
                ADD CONSTRAINT products_box_length_positive CHECK (box_length_cm IS NULL OR box_length_cm > 0),
                ADD CONSTRAINT products_box_width_positive CHECK (box_width_cm IS NULL OR box_width_cm > 0),
                ADD CONSTRAINT products_box_height_positive CHECK (box_height_cm IS NULL OR box_height_cm > 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_net_weight_positive,
                DROP CONSTRAINT IF EXISTS products_shipping_weight_positive,
                DROP CONSTRAINT IF EXISTS products_length_positive,
                DROP CONSTRAINT IF EXISTS products_width_positive,
                DROP CONSTRAINT IF EXISTS products_height_positive,
                DROP CONSTRAINT IF EXISTS products_pieces_per_box_positive,
                DROP CONSTRAINT IF EXISTS products_box_weight_positive,
                DROP CONSTRAINT IF EXISTS products_box_length_positive,
                DROP CONSTRAINT IF EXISTS products_box_width_positive,
                DROP CONSTRAINT IF EXISTS products_box_height_positive;
        SQL);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'net_weight_grams', 'shipping_weight_grams',
                'length_cm', 'width_cm', 'height_cm',
                'ships_by_box', 'pieces_per_box', 'box_weight_grams',
                'box_length_cm', 'box_width_cm', 'box_height_cm',
                'is_fragile',
            ]);
        });
    }
};
