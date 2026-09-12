<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which mode a payment was actually taken in (§26.4).
 *
 * Sandbox and live are a setting an administrator can change. Without this
 * column, flipping that switch would silently reinterpret every historical
 * payment as having been taken in the new mode — a test transaction would start
 * reading as real money, or real money as a test.
 *
 * So the mode is stamped on the payment when it is handed to the gateway and is
 * never recomputed. Nullable because payments recorded before a gateway was
 * chosen have no mode to record, and because the rows that already exist were
 * taken before this was captured: an invented value would be worse than an
 * honest blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_mode', 16)->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_mode');
        });
    }
};
