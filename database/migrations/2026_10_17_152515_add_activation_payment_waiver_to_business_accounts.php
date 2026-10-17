<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An administrator's decision to waive the activation payment.
 *
 * Recorded on the account rather than as a fabricated payment: no money moved,
 * so no payment row and no ledger entry may claim it did. The waiver names who
 * decided, when and why, and `ActivationRequirements` reads it as the third
 * condition being met by decision instead of by settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->timestampTz('activation_payment_waived_at')->nullable();
            $table->foreignId('activation_payment_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('activation_payment_waiver_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activation_payment_waived_by');
            $table->dropColumn(['activation_payment_waived_at', 'activation_payment_waiver_reason']);
        });
    }
};
