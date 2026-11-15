<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A new storefront no longer asks a cash-on-delivery customer for an SMS code
 * unless its Partner (or the platform policy) turns it on. Existing storefronts
 * keep the value they have; `php artisan website:sms-verification off` switches
 * them all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function ($table) {
            $table->boolean('sms_verification_enabled')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function ($table) {
            $table->boolean('sms_verification_enabled')->default(true)->change();
        });
    }
};
