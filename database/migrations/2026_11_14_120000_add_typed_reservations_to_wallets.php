<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Typed reservations: how much of what is set aside came from platform credit.
 *
 * A reservation or a hold is a claim on a wallet's money — a withdrawal, a
 * platform purchase, a fee. When two kinds of money sit in the wallet the claim
 * must remember exactly how much of each it took, so releasing it, capturing it,
 * refunding it or reversing it hands back the same kinds and never turns one into
 * the other. The wallet keeps the platform-credit part of each bucket; the rest of
 * the bucket is withdrawable money.
 *
 * Every existing reservation and hold was taken before platform credit existed,
 * so every one of them is entirely withdrawable money, which is what a default of
 * zero says. Nothing is rewritten.
 *
 * Additive and safe to roll back: the columns hold nothing the ledger does not,
 * and dropping them returns the wallet to exactly the shape the previous
 * migration left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('reserved_platform_credit', 19, 2)->default('0.00');
            $table->decimal('hold_platform_credit', 19, 2)->default('0.00');
        });

        Schema::table('supplier_wallets', function (Blueprint $table): void {
            $table->decimal('reserved_platform_credit', 19, 2)->default('0.00');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE wallets
                ADD CONSTRAINT wallets_typed_claims_not_negative
                CHECK (reserved_platform_credit >= 0 AND hold_platform_credit >= 0);
            ALTER TABLE supplier_wallets
                ADD CONSTRAINT supplier_wallets_typed_reservation_not_negative
                CHECK (reserved_platform_credit >= 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_wallets DROP CONSTRAINT IF EXISTS supplier_wallets_typed_reservation_not_negative;
            ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallets_typed_claims_not_negative;
        SQL);

        Schema::table('supplier_wallets', function (Blueprint $table): void {
            $table->dropColumn('reserved_platform_credit');
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn(['reserved_platform_credit', 'hold_platform_credit']);
        });
    }
};
