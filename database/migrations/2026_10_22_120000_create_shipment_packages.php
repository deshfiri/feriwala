<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One or more physical packages making up a shipment (Advanced Order
 * Management batch, Commit 5).
 *
 * Dimensions are optional at this stage -- Commit 6 adds per-product
 * logistics fields a package can be built from, but a package can also be
 * entered by hand with just a weight, which is all the manual courier driver
 * needs to record a shipment today (D8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();

            $table->decimal('weight', 8, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->string('package_size', 32)->nullable();

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shipment_packages
                ADD CONSTRAINT shipment_packages_weight_positive CHECK (weight IS NULL OR weight > 0),
                ADD CONSTRAINT shipment_packages_dimensions_positive CHECK (
                    (length IS NULL OR length > 0) AND
                    (width IS NULL OR width > 0) AND
                    (height IS NULL OR height > 0)
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_packages');
    }
};
