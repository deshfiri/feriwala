<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two options a Partner sets on their own storefront.
 *
 * - `banner_link_url`: where the banner image goes when it is clicked. The image
 *   itself is the link; nothing is drawn over it. Empty means the banner is a
 *   plain picture.
 * - `sms_verification_enabled`: whether a cash-on-delivery customer must confirm
 *   their order with an SMS code. On by default, which is how every shop has
 *   always behaved, so no existing storefront changes. A platform policy can
 *   require it whatever this says (see `CheckoutVerification`).
 *
 * Additive and reversible; no existing row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('banner_link_url', 2048)->nullable()->after('banner_path');
            $table->boolean('sms_verification_enabled')->default(true);
        });

        DB::statement("ALTER TABLE websites ADD CONSTRAINT websites_banner_link_not_blank CHECK (banner_link_url IS NULL OR btrim(banner_link_url) <> '')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE websites DROP CONSTRAINT IF EXISTS websites_banner_link_not_blank');

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['banner_link_url', 'sms_verification_enabled']);
        });
    }
};
