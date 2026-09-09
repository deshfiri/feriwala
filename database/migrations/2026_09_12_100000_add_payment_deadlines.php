<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long an unpaid checkout stays open (§9, P1-48).
 *
 * §9 lists "Payment deadline" among the things an administrator configures. The
 * deadline is **stamped onto the payment** rather than derived from the setting
 * each time it is read: an applicant told they have until Friday must still have
 * until Friday after somebody shortens the window on Wednesday.
 *
 * `cancelled_at` beside the existing `failed_at` because they are different
 * events. A failure is the gateway saying no; running out of time is nobody
 * saying anything at all, and a report on failed payments should not be full of
 * checkouts that were simply never paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('initiated_at');
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');

            // The sweep's whole query: unpaid, and past its deadline.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['expires_at', 'cancelled_at']);
        });
    }
};
