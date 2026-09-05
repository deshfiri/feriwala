<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a KYC deadline has been acted on (§7.4).
 *
 * The sweep runs daily and the consequences it applies — restricting an active
 * account, notifying, writing an audit entry — must happen once, not once per
 * day for as long as the round stays overdue. Without a marker the applicant
 * gets the same SMS every morning and the audit log fills with duplicates of a
 * single event.
 *
 * A timestamp rather than a boolean, because "when did we act on this" is the
 * question a dispute actually asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->timestamp('deadline_warned_at')->nullable()->after('deadline_at');
            $table->timestamp('deadline_enforced_at')->nullable()->after('deadline_warned_at');

            // The sweep's own query: find rounds past their deadline that have
            // not been acted on. Without this it is a full scan of every
            // submission ever made, every day (§37).
            $table->index(['deadline_at', 'deadline_enforced_at']);
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropIndex(['deadline_at', 'deadline_enforced_at']);
            $table->dropColumn(['deadline_warned_at', 'deadline_enforced_at']);
        });
    }
};
