<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One provider transaction, one payment — and somewhere to say when that broke
 * down (§26.4, §42).
 *
 * `gateway_reference` becomes **unique**. It is the provider's own transaction
 * identity, and two payments claiming it would mean one payment of real money
 * settling two orders. A check in code cannot hold that line under concurrent
 * callbacks; the index can. Nulls do not collide in PostgreSQL, so a payment
 * that has not reached a gateway yet is unaffected.
 *
 * `reconciliation_reason` records why a payment needs a human. The case this
 * exists for is a verified success arriving after its checkout expired: the
 * money is real, the purchase is not being revived, and neither of those facts
 * may be dropped on the floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('reconciliation_reason')->nullable()->after('failure_reason');
            $table->timestamp('reconciliation_required_at')->nullable()->after('reconciliation_reason');

            $table->unique('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['gateway_reference']);
            $table->dropColumn(['reconciliation_reason', 'reconciliation_required_at']);
        });
    }
};
