<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner onboarding v2 (D39): pay first, then KYC, personal details and
 * business setup, then an application a person reviews.
 *
 *   - `personal_details_completed_at` / `business_setup_completed_at` — when the
 *     partner last completed each step; cleared when a reviewer asks for that
 *     part to be corrected.
 *   - `application_submitted_at` — the partner's "Submit application". The
 *     account reaches the approval gate only once it is set (with every other
 *     requirement), and a correction request clears it.
 *   - `business_mobile` / `business_email` — optional; null means "use the
 *     owner's own".
 *   - `preferred_domain_name` / `preferred_domain_extension` — the domain the
 *     partner would like, saved for staff to check and buy by hand (D9). Never
 *     bought automatically.
 *   - `correction_sections` / `correction_feedback` — what a reviewer asked to
 *     be corrected and what the partner is told, until they resubmit.
 *
 * And a `residential` kind in the shared address book, for the owner's home
 * address when it is in Bangladesh (structured BD locations).
 *
 * Accounts already at the approval gate under the earlier order — and accounts
 * already active — completed the application as it then was, so they are
 * treated as submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->timestamp('personal_details_completed_at')->nullable();
            $table->timestamp('business_setup_completed_at')->nullable();
            $table->timestamp('application_submitted_at')->nullable();
            $table->string('business_mobile', 20)->nullable();
            $table->string('business_email', 191)->nullable();
            $table->string('preferred_domain_name', 63)->nullable();
            $table->string('preferred_domain_extension', 24)->nullable();
            $table->jsonb('correction_sections')->nullable();
            $table->text('correction_feedback')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE business_accounts
                ADD CONSTRAINT business_accounts_business_mobile_e164 CHECK (
                    business_mobile IS NULL OR business_mobile ~ '^\+[1-9][0-9]{6,14}$'
                ),
                ADD CONSTRAINT business_accounts_preferred_domain_whole CHECK (
                    (preferred_domain_name IS NULL AND preferred_domain_extension IS NULL)
                    OR (preferred_domain_name IS NOT NULL AND preferred_domain_extension IS NOT NULL)
                ),
                ADD CONSTRAINT business_accounts_preferred_domain_label CHECK (
                    preferred_domain_name IS NULL
                    OR preferred_domain_name ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$'
                ),
                ADD CONSTRAINT business_accounts_preferred_domain_extension_form CHECK (
                    preferred_domain_extension IS NULL
                    OR preferred_domain_extension ~ '^\.[a-z]{2,20}(\.[a-z]{2,3})?$'
                );

            UPDATE business_accounts
               SET application_submitted_at = COALESCE(approval_pending_at, activated_at, updated_at)
             WHERE status = 'approval_pending' OR activated_at IS NOT NULL;

            ALTER TABLE shared_addresses DROP CONSTRAINT shared_addresses_type_matches_owner;
            ALTER TABLE shared_addresses ADD CONSTRAINT shared_addresses_type_matches_owner CHECK (
                (owner_type = 'business_account' AND type IN ('business', 'operational', 'residential'))
                OR (owner_type = 'supplier' AND type IN ('registered', 'pickup', 'return'))
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM shared_addresses WHERE type = 'residential';
            ALTER TABLE shared_addresses DROP CONSTRAINT shared_addresses_type_matches_owner;
            ALTER TABLE shared_addresses ADD CONSTRAINT shared_addresses_type_matches_owner CHECK (
                (owner_type = 'business_account' AND type IN ('business', 'operational'))
                OR (owner_type = 'supplier' AND type IN ('registered', 'pickup', 'return'))
            );

            ALTER TABLE business_accounts
                DROP CONSTRAINT IF EXISTS business_accounts_business_mobile_e164,
                DROP CONSTRAINT IF EXISTS business_accounts_preferred_domain_whole,
                DROP CONSTRAINT IF EXISTS business_accounts_preferred_domain_label,
                DROP CONSTRAINT IF EXISTS business_accounts_preferred_domain_extension_form;
        SQL);

        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'personal_details_completed_at', 'business_setup_completed_at', 'application_submitted_at',
                'business_mobile', 'business_email', 'preferred_domain_name', 'preferred_domain_extension',
                'correction_sections', 'correction_feedback',
            ]);
        });
    }
};
