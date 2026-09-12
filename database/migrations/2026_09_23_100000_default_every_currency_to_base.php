<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every money table defaults to the base currency (D4, §26.4).
 *
 * All nineteen tables that hold an amount already carried `currency_code` —
 * that part of D4 was done as each was built. What was inconsistent is the
 * default: twelve defaulted to BDT and seven required every insert to say so.
 *
 * Both are safe, because all of them are NOT NULL. But the inconsistency is the
 * kind that reads as significance when it is really just the order things were
 * written in, and somebody will eventually wonder what it means that an invoice
 * line has to state its currency while a payment does not.
 *
 * `payment_logs` is deliberately left alone. Its currency is nullable because
 * the most interesting rows in it are notifications that carry no amount at
 * all, and defaulting those to BDT would invent a currency for a message that
 * never mentioned money.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const TABLES = [
        'coupon_redemptions',
        'coupons',
        'fee_rules',
        'invoice_lines',
        'invoices',
        'payment_tax_lines',
        'refund_requests',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN currency_code SET DEFAULT 'BDT'");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN currency_code DROP DEFAULT");
        }
    }
};
