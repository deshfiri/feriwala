<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff's manual check of a partner's preferred domain (D39).
 *
 * There is no registrar connection, so availability is a fact only a person
 * records: who checked, when, and what they found. Cleared whenever the partner
 * chooses a different domain, so a check never vouches for a name it did not
 * look at. Checked again before the domain is bought (D9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->string('preferred_domain_check_result', 16)->nullable();
            $table->timestamp('preferred_domain_checked_at')->nullable();
            $table->foreignId('preferred_domain_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('preferred_domain_check_note', 500)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE business_accounts
                ADD CONSTRAINT business_accounts_domain_check_result_known CHECK (
                    preferred_domain_check_result IS NULL OR preferred_domain_check_result IN ('available', 'unavailable')
                ),
                ADD CONSTRAINT business_accounts_domain_check_whole CHECK (
                    (preferred_domain_check_result IS NULL AND preferred_domain_checked_at IS NULL)
                    OR (preferred_domain_check_result IS NOT NULL AND preferred_domain_checked_at IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE business_accounts
                DROP CONSTRAINT IF EXISTS business_accounts_domain_check_result_known,
                DROP CONSTRAINT IF EXISTS business_accounts_domain_check_whole;
        SQL);

        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_domain_checked_by');
            $table->dropColumn(['preferred_domain_check_result', 'preferred_domain_checked_at', 'preferred_domain_check_note']);
        });
    }
};
