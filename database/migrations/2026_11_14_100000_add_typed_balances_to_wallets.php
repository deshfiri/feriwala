<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Typed wallet balances: platform credit and withdrawable money.
 *
 * One wallet, one ledger, two kinds of money. The wallet stores only the
 * **platform-credit** part; withdrawable is the rest of `total`. That is what
 * makes this migration preserve every balance exactly: every wallet starts with
 * `platform_credit = 0`, so everything already in it is withdrawable, and no
 * row of any ledger is touched.
 *
 * Each ledger entry records which kind it moved. Both ledgers are append-only
 * and guarded by triggers, so the new column is added with a constant default
 * (`withdrawable`) rather than backfilled: PostgreSQL answers existing rows from
 * the default without rewriting them, and history reads as it always did —
 * everything that happened before this change moved withdrawable money.
 *
 * - `wallet_transactions.platform_credit_amount` is the debit/credit allocation:
 *   how much of the posting was platform credit. The rest was withdrawable. It is
 *   how a refund or a reversal finds the types an earlier posting used.
 * - `conversion_reference` ties together the two halves of a conversion of
 *   platform credit into withdrawable money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('platform_credit', 19, 2)->default('0.00');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->decimal('platform_credit_amount', 19, 2)->default('0.00');
            $table->string('conversion_reference', 26)->nullable()->index();
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->string('balance_type', 20)->default('withdrawable');
            $table->string('conversion_reference', 26)->nullable()->index();
        });

        Schema::table('supplier_wallets', function (Blueprint $table): void {
            $table->decimal('platform_credit', 19, 2)->default('0.00');
        });

        Schema::table('supplier_ledger_entries', function (Blueprint $table): void {
            $table->string('balance_type', 20)->default('withdrawable');
            $table->string('conversion_reference', 26)->nullable()->index();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE wallets
                ADD CONSTRAINT wallets_platform_credit_not_negative CHECK (platform_credit >= 0);
            ALTER TABLE supplier_wallets
                ADD CONSTRAINT supplier_wallets_platform_credit_not_negative CHECK (platform_credit >= 0);
            ALTER TABLE wallet_transactions
                ADD CONSTRAINT wallet_transactions_platform_credit_amount_within_amount
                CHECK (platform_credit_amount >= 0 AND platform_credit_amount <= amount);
            ALTER TABLE ledger_entries
                ADD CONSTRAINT ledger_entries_balance_type_known
                CHECK (balance_type IN ('platform_credit', 'withdrawable'));
            ALTER TABLE supplier_ledger_entries
                ADD CONSTRAINT supplier_ledger_entries_balance_type_known
                CHECK (balance_type IN ('platform_credit', 'withdrawable'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_ledger_entries DROP CONSTRAINT IF EXISTS supplier_ledger_entries_balance_type_known;
            ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_balance_type_known;
            ALTER TABLE wallet_transactions DROP CONSTRAINT IF EXISTS wallet_transactions_platform_credit_amount_within_amount;
            ALTER TABLE supplier_wallets DROP CONSTRAINT IF EXISTS supplier_wallets_platform_credit_not_negative;
            ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallets_platform_credit_not_negative;
        SQL);

        Schema::table('supplier_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn(['balance_type', 'conversion_reference']);
        });

        Schema::table('supplier_wallets', function (Blueprint $table): void {
            $table->dropColumn('platform_credit');
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropColumn(['balance_type', 'conversion_reference']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropColumn(['platform_credit_amount', 'conversion_reference']);
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn('platform_credit');
        });
    }
};
