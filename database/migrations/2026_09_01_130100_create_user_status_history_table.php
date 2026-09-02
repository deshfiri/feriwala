<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every account status change, with who and why (§5.3, §7.3, §36.2).
 *
 * The columns mirror what §7.3 demands of a KYC decision — reviewer, previous
 * status, new status, timestamp, reason, internal note, user-visible feedback —
 * because the same questions get asked of any status change during a dispute.
 *
 * Append-only, like the audit log. A history that can be edited cannot settle an
 * argument about what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);

            // Null when the system moved the account — a scheduled KYC deadline
            // check, a low-balance restriction, a payment callback.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('reason')->nullable();

            // Kept apart on purpose: the internal note is for staff, the
            // user-visible note is shown to the account holder. Merging them is
            // how private review comments end up in front of a customer (§7.3).
            $table->text('internal_note')->nullable();
            $table->text('user_visible_note')->nullable();

            // §18.3 requires knowing whether the person was told.
            $table->boolean('notified')->default(false);
            $table->timestamp('notified_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_status_history');
    }
};
