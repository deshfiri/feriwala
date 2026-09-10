<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment logs (§42).
 *
 * Every conversation with a gateway, in one place, so "what actually happened to
 * this payment" has an answer that does not depend on grepping a file that may
 * have rotated away.
 *
 * `payment_id` is **nullable** on purpose. The most interesting entries are
 * often the ones that could not be matched to a payment at all — a signed
 * notification for a reference nobody recognises, a callback arriving after a
 * record was removed — and dropping those on the floor would lose exactly the
 * evidence somebody needs.
 *
 * §42 is explicit that logs must not contain passwords, security tokens, API
 * secrets, gateway secrets, complete payment credentials or unmasked personal
 * data. The `context` column is written through a redactor rather than trusted
 * to callers, because "remember to strip the store password" is a rule that gets
 * forgotten exactly once.
 *
 * Append-only, like the audit log. A log somebody can edit is not a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 40);

            // Which way the message went, and what it was about.
            $table->string('direction', 16);
            $table->string('event', 40);

            // Our reference and the provider's, both nullable: an unmatched
            // notification may carry one, the other, or neither.
            $table->string('reference', 64)->nullable();
            $table->string('gateway_reference')->nullable();

            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency_code', 3)->nullable();

            // What came of it — settled, refused, unverifiable, ignored.
            $table->string('outcome', 40)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();

            // Redacted before it gets here. Never the raw payload.
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at');

            $table->index(['payment_id', 'created_at']);
            $table->index(['gateway', 'created_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
