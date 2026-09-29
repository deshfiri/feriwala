<?php

use App\Domain\Withdrawal\AccountWithdrawalLimits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account withdrawal limit overrides, mirroring `suppliers.
 * withdrawal_minimum_override`/`withdrawal_maximum_override` for the
 * Client/Partner side ({@see AccountWithdrawalLimits}).
 * Stored as flat Taka directly (D26) -- unlike the Supplier columns, which
 * predate the flat-Taka conversion and were renamed onto it after the fact,
 * this is new production code and starts on `decimal(19,2)` from the first
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->decimal('withdrawal_minimum_override', 19, 2)->nullable();
            $table->decimal('withdrawal_maximum_override', 19, 2)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE business_accounts
                ADD CONSTRAINT business_accounts_withdrawal_overrides_not_negative CHECK (
                    (withdrawal_minimum_override IS NULL OR withdrawal_minimum_override >= 0)
                    AND (withdrawal_maximum_override IS NULL OR withdrawal_maximum_override >= 0)
                ),
                ADD CONSTRAINT business_accounts_withdrawal_override_range CHECK (
                    withdrawal_minimum_override IS NULL
                    OR withdrawal_maximum_override IS NULL
                    OR withdrawal_minimum_override <= withdrawal_maximum_override
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE business_accounts
                DROP CONSTRAINT IF EXISTS business_accounts_withdrawal_override_range,
                DROP CONSTRAINT IF EXISTS business_accounts_withdrawal_overrides_not_negative;
        SQL);

        Schema::table('business_accounts', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_minimum_override', 'withdrawal_maximum_override']);
        });
    }
};
