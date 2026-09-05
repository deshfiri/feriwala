<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event-level idempotency for KYC deadlines, and the dashboard notification
 * store (§7.4, §18.3, D20).
 *
 * The two nullable timestamps this replaces were markers, not guarantees. A
 * marker written inside a transaction stops the *next* daily run, but two
 * workers reaching the same row together — a scheduler retry overlapping a slow
 * first attempt — could both read "not yet done" before either wrote. The window
 * is small and the consequence is not: a duplicate SMS and a duplicate audit
 * entry for one event.
 *
 * A unique index closes it. Claiming the event is the insert, so the second
 * attempt loses at the database rather than on timing, and the claim is what
 * grants the right to notify. Rows are also the record of what happened and
 * when, which the timestamps could only answer one event at a time.
 *
 * `notifications` is Laravel's own shape. D20 makes dashboard notifications
 * non-optional for account status, KYC and security events: mail can be missed,
 * filtered, or sent to an address nobody reads, and a restriction nobody was
 * told about is indistinguishable from one that was hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_deadline_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // 'warned' | 'enforced' | 'restored'
            $table->string('event', 20);

            // What was decided, for the record: whether the account was actually
            // restricted, and the deadline the decision was made against.
            $table->boolean('account_restricted')->default(false);
            $table->timestamp('deadline_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The guarantee. One row per event per round, enforced by the
            // database rather than by the order two workers happen to run in.
            $table->unique(['kyc_submission_id', 'event']);

            $table->index(['business_account_id', 'event']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The dashboard's own query: this person's unread notifications,
            // newest first (§37).
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropIndex(['deadline_at', 'deadline_enforced_at']);
            $table->dropColumn(['deadline_warned_at', 'deadline_enforced_at']);
            $table->index('deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropIndex(['deadline_at']);
            $table->timestamp('deadline_warned_at')->nullable()->after('deadline_at');
            $table->timestamp('deadline_enforced_at')->nullable()->after('deadline_warned_at');
            $table->index(['deadline_at', 'deadline_enforced_at']);
        });

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('kyc_deadline_events');
    }
};
