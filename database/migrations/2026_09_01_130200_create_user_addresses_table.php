<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account addresses (§5.2).
 *
 * A user may hold several — present, permanent, billing, shipping — and §5.2
 * treats present and permanent as distinct fields rather than one address with a
 * flag.
 *
 * Orders keep their own immutable address snapshot (contract §6.2). These rows
 * are the account's current addresses; editing one must never rewrite where a
 * past order was actually delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);

            $table->string('label')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_mobile', 20)->nullable();

            $table->string('line_1');
            $table->string('line_2')->nullable();
            $table->string('area')->nullable();
            $table->string('city');
            $table->string('district')->nullable();
            $table->string('postcode', 16)->nullable();
            $table->string('country', 2)->default('BD');

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
