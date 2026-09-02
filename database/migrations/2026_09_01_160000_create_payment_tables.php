<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments and their components (§9, §26).
 *
 * `payment_allocations` is the table §5.1 and §9 actually require: one payment
 * split into separately stored components, so the registration fee and package
 * fee can be reported, invoiced, reconciled, and refunded independently even
 * though they were paid together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What this payment is for — activation, renewal, an order, a
            // top-up (§26.3).
            $table->string('purpose', 40);
            $table->string('status', 32);

            // Gateway is null until one is chosen, so a quote can be recorded
            // before the user picks how to pay.
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_reference')->nullable();

            // The amount actually charged, and what Feriwala keeps of it. Held
            // as minor units with an explicit currency (D4).
            $table->bigInteger('amount_minor');
            $table->bigInteger('revenue_minor')->default(0);
            $table->string('currency_code', 3)->default('BDT');

            /*
             * §26.4 and §36.1: duplicate payment prevention.
             *
             * A unique index rather than an application check — two concurrent
             * callbacks would both pass a check-then-insert, and the database is
             * the only place that can settle the race.
             */
            $table->string('idempotency_key', 128)->nullable()->unique();

            // What the gateway actually settled, when it differs from the
            // requested currency (D4). Never rewrites the base amount.
            $table->string('settled_currency_code', 3)->nullable();
            $table->bigInteger('settled_amount_minor')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            // Anything linkable — the package being bought, the order, the
            // website. Kept generic so a payment can point at whatever caused it.
            $table->nullableMorphs('payable');

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('gateway_reference');
            $table->index('purpose');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);

            // Always positive. A deduction is identified by its type, not by a
            // negative amount, so summing "total discount" needs no sign logic.
            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3)->default('BDT');

            $table->string('description')->nullable();

            // Preserves the order the user saw at checkout, so the invoice and
            // the receipt read identically to the quote.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['payment_id', 'sort_order']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
