<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the partner typed when the address was not a plain subdomain label — their
 * own domain, a URL, anything. It is a request, not a claim: `domain` stays the
 * one staff verify and provision (D9), so asking for an address never holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('requested_address', 253)->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('requested_address');
        });
    }
};
