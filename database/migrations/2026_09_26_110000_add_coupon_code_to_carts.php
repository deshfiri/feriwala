<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coupon code somebody entered at wholesale checkout (§14, P4-6).
 *
 * Only the code is kept, so it can be tried again on every quote. What it is
 * worth is never stored: the discount is worked out on the server each time,
 * against the subtotal priced at that moment, and a code that has since expired
 * or run out simply stops applying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('coupon_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });
    }
};
