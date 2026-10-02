<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dated delivery-charge rule, by chargeable weight and an optional area or
 * courier scope (beta-critical batch, Commit 2).
 *
 * The same shape `fee_rules` already uses for the same reason (§9, D19): a
 * rule is never edited into a new price, only closed and replaced by a new
 * row with its own window, so "what did delivery cost in March" has an
 * answer and a reissued quote reproduces its own arithmetic. Weight bounds
 * are whole grams -- the same canonical unit `products`/`product_variants`
 * logistics columns use -- so a tier lookup is an exact integer comparison,
 * never a division.
 *
 * Scoped by specificity, the same outward walk {@see
 * \App\Domain\Billing\FeeRuleResolver} already does for `fee_rules`: a rule
 * naming both a courier and an area beats one naming only an area, which
 * beats one naming only a courier, which beats the fully general rule.
 * `area` is a free-text label an administrator defines rather than a link
 * into the Bangladesh location hierarchy -- this batch does not introduce a
 * delivery-zone taxonomy, only the override point for whichever one a later
 * batch adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_charge_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->unsignedInteger('weight_from_grams')->default(0);
            // Null is unbounded -- the top-open tier.
            $table->unsignedInteger('weight_to_grams')->nullable();

            $table->string('currency_code', 3)->default('BDT');
            $table->decimal('base_charge', 19, 2);
            // Null means the global additional-per-kg setting applies.
            $table->decimal('per_kg_charge', 19, 2)->nullable();

            $table->string('area', 120)->nullable();
            $table->foreignId('courier_provider_id')->nullable()
                ->constrained('courier_providers')->nullOnDelete();

            $table->unsignedSmallInteger('priority')->default(0);

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['weight_from_grams', 'weight_to_grams']);
            $table->index(['area', 'courier_provider_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE delivery_charge_rules
                ADD CONSTRAINT delivery_charge_rules_base_charge_not_negative CHECK (base_charge >= 0),
                ADD CONSTRAINT delivery_charge_rules_per_kg_charge_not_negative CHECK (per_kg_charge IS NULL OR per_kg_charge >= 0),
                ADD CONSTRAINT delivery_charge_rules_weight_range_valid CHECK (
                    weight_to_grams IS NULL OR weight_to_grams > weight_from_grams
                ),
                ADD CONSTRAINT delivery_charge_rules_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_charge_rules');
    }
};
