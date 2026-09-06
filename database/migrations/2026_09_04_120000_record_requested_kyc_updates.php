<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A KYC round an administrator asked for (§7.2).
 *
 * §7.2 lets an authorised user "request future KYC updates" from an account
 * that is already trading — a document has expired, a rule has changed, a
 * periodic re-verification falls due. That round has to be distinguishable from
 * one the applicant opened themselves, because the two mean different things to
 * everyone who reads them: one is somebody working through onboarding, the
 * other is a request the business now has to answer.
 *
 * The reason and the instruction are separate columns, exactly as they are on
 * `kyc_reviews` (§7.3). The internal reason is why we asked; the instruction is
 * what the account holder is told to do. Collapsing them puts a note meant for
 * colleagues in front of a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            // Null for a round the applicant opened themselves, which is the
            // normal onboarding case.
            $table->timestamp('requested_at')->nullable()->after('deadline_at');
            $table->foreignId('requested_by')->nullable()->after('requested_at')
                ->constrained('users')->nullOnDelete();

            $table->text('request_reason')->nullable()->after('requested_by');
            $table->text('request_instructions')->nullable()->after('request_reason');

            // The §7.4 sweep looks for overdue rounds; this narrows it to the
            // requested ones when reporting on outstanding update requests.
            $table->index(['requested_at', 'deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropIndex(['requested_at', 'deadline_at']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['requested_at', 'request_reason', 'request_instructions']);
        });
    }
};
