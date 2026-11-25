<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KYC for onboarding v2 (D39 step 4).
 *
 *   - `kyc_submissions.issuing_country` — the country that issued the identity
 *     document the partner submitted (ISO 3166-1 alpha-2). Null on rounds from
 *     before it was asked.
 *   - `kyc_submissions.selfie_mode` — whether this round asks for a selfie, as
 *     resolved for this partner when the round was a draft: the partner's own
 *     override, else the platform setting, else (null) the KYC batch's own
 *     setting. Frozen once the round is submitted.
 *   - `business_accounts.kyc_selfie_mode` — an administrator's override for one
 *     partner; null follows the platform setting.
 *
 * Nothing existing is changed: every new column is null on every existing row,
 * which means "as before".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->char('issuing_country', 2)->nullable();
            $table->string('selfie_mode', 16)->nullable();
        });

        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->string('kyc_selfie_mode', 16)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_submissions
                ADD CONSTRAINT kyc_submissions_issuing_country_form CHECK (
                    issuing_country IS NULL OR issuing_country ~ '^[A-Z]{2}$'
                ),
                ADD CONSTRAINT kyc_submissions_selfie_mode_known CHECK (
                    selfie_mode IS NULL OR selfie_mode IN ('disabled', 'optional', 'required')
                );

            ALTER TABLE business_accounts
                ADD CONSTRAINT business_accounts_kyc_selfie_mode_known CHECK (
                    kyc_selfie_mode IS NULL OR kyc_selfie_mode IN ('disabled', 'optional', 'required')
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_submissions
                DROP CONSTRAINT IF EXISTS kyc_submissions_issuing_country_form,
                DROP CONSTRAINT IF EXISTS kyc_submissions_selfie_mode_known;
            ALTER TABLE business_accounts
                DROP CONSTRAINT IF EXISTS business_accounts_kyc_selfie_mode_known;
        SQL);

        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->dropColumn('kyc_selfie_mode');
        });

        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->dropColumn(['issuing_country', 'selfie_mode']);
        });
    }
};
