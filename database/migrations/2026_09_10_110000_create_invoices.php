<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was billed, kept exactly as it was billed (§8.2, §9).
 *
 * An invoice is not a view over a payment. A payment can be retried, fail,
 * settle late, be partly refunded or be reconciled against a gateway that
 * reports something different; the document sent to a customer must go on
 * saying what it said. So the lines are **copied** at issue rather than joined
 * at read time, and both tables refuse updates and deletes the way the audit log
 * does.
 *
 * Amounts are minor units and the currency is stored beside them, so a figure
 * can never be read against the wrong currency (§36.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Addressed by ULID, like every other public record: an invoice URL
            // with a row number in it is one somebody can count through.
            $table->ulid('public_id')->unique();

            /*
             * The number a customer quotes on the phone. Unique, and never
             * reissued — an invoice number that appears twice is worse than one
             * that skips.
             */
            $table->string('number', 32)->unique();

            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            /*
             * The payment this bills for. Unique, because one payment is one
             * invoice — that is what makes issuing idempotent under a retried
             * request or a duplicated callback, at the index rather than in
             * application code.
             */
            $table->foreignId('payment_id')->nullable()->unique()
                ->constrained()->nullOnDelete();

            $table->string('purpose', 40);

            $table->string('currency_code', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('total_minor');

            $table->timestamp('issued_at')->useCurrent();

            $table->timestamps();

            $table->index(['business_account_id', 'issued_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);

            /*
             * The label as it read at issue. Not looked up from the allocation
             * type at render time: a fee renamed next year must not retitle a
             * line on an invoice somebody already has.
             */
            $table->string('label', 191);

            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3);

            // Kept rather than derived, so a reader never has to know which
            // types happen to be deductions to add the column up.
            $table->boolean('is_deduction')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
