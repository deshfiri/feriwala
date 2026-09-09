<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a fee is, and when it was that (§9).
 *
 * The registration fee lived in a settings row: one value, no history, and
 * rewriting it silently changed what every past quote *would* have said. Money
 * already charged was safe — payments snapshot their allocations — but "what
 * was the fee in March" had no answer, and raising it left nothing recording
 * that it had been raised.
 *
 * So a fee is a **dated rule**. Rules are never edited into a new price: a new
 * price is a new row with its own window, and the old one closes. That is the
 * same shape the tax rates already use, for the same reason (D19).
 *
 * Scoped, most specific first. A rule naming a package beats the global one,
 * which is exactly §9's "Global Registration Fee" and "Package-specific
 * Registration Fee" without two mechanisms for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            /*
             * Which fee this sets. Only the registration fee today; the column
             * exists because §9 lists several configurable fees and a table
             * called `fee_rules` that can hold exactly one would have to be
             * migrated the first time a second arrives.
             */
            $table->string('fee_type', 40);

            /*
             * Null is the global rule. A package id makes it specific to that
             * package, and specificity wins outright — priority never crosses
             * levels, the same rule the tax resolver follows.
             */
            $table->foreignId('package_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3);

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();

            $table->boolean('is_active')->default(true);

            // Why this price, for the person who finds it in two years.
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['fee_type', 'package_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rules');
    }
};
