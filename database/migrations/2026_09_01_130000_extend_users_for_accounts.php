<?php

use App\Domain\Account\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends users into Feriwala accounts (§5).
 *
 * One account structure — no separate customer, partner, wholesale, or
 * dropshipping account, and no conversion between them (§5, §44). Everything
 * that distinguishes one user from another is a status, a package entitlement,
 * or a role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Public identifier for URLs and API payloads — never the id (§34.2).
            $table->ulid('public_id')->nullable()->after('id');

            $table->string('status', 40)->default(AccountStatus::Registered->value)->after('public_id');
            $table->timestamp('activated_at')->nullable()->after('status');

            // Mobile is a first-class identifier in Bangladesh — often more
            // reliable than email — and is verified during onboarding (§5.1).
            $table->string('mobile', 20)->nullable()->after('email');
            $table->timestamp('mobile_verified_at')->nullable()->after('mobile');

            // §5.2 registration fields.
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('country', 2)->default('BD');
            $table->string('nationality', 64)->nullable();

            // §25.1: every active user gets a code and can refer without limit.
            // The referrer is captured at registration and, once the referral
            // qualifies, must never be reassigned (§25.5).
            $table->string('referral_code', 16)->nullable();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Interface language (D6).
            $table->string('locale', 5)->default('en');

            // §5.2 acceptance, recorded rather than assumed.
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('public_id');
            $table->unique('mobile');
            $table->unique('referral_code');

            // §37 names these as required indexes.
            $table->index('status');
            $table->index('activated_at');
            $table->index('referred_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by_user_id']);

            $table->dropColumn([
                'public_id', 'status', 'activated_at',
                'mobile', 'mobile_verified_at',
                'date_of_birth', 'gender', 'country', 'nationality',
                'referral_code', 'referred_by_user_id', 'locale',
                'terms_accepted_at', 'privacy_accepted_at',
            ]);
        });
    }
};
