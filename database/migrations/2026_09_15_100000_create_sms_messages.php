<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery status, failed-SMS logs and history (§30.2, §42).
 *
 * One row per message §30 decided to send, written **before** anything is
 * handed to a provider. A record created only on success cannot answer "what
 * happened to the message I never received", which is the only question anybody
 * asks of an SMS log.
 *
 * `dedupe_key` is unique, and that is what stops a retry from becoming a second
 * message. The queue retries on transient failures; the business event happened
 * once, and the customer must hear about it once.
 *
 * The recipient is stored in full, deliberately. This is a delivery record
 * rather than a diagnostic log — a history that cannot say which number a
 * message went to is no use to the person trying to work out why it never
 * arrived. Diagnostic logs mask it (§42); this does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_account_id')->nullable()->constrained()->nullOnDelete();

            // The business event this message is about. §30 switches SMS on and
            // off per event, and a report of "what did we send about payments"
            // needs it stored rather than inferred from the body.
            $table->string('event', 60);

            $table->string('recipient', 32);
            $table->string('locale', 5);
            $table->text('body');

            // Bangla is UCS-2, so a segment is 70 characters rather than 160.
            // Counted at composition and kept, for cost tracking (§30.2).
            $table->unsignedSmallInteger('segments')->default(1);

            $table->string('status', 20);
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('cost', 32)->nullable();

            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // One message per business event per recipient. The retry that makes
            // delivery reliable is the same retry that would otherwise make it
            // repetitive.
            $table->string('dedupe_key', 64)->unique();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
