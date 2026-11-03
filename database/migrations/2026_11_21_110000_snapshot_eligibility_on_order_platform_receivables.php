<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The account type and ownership a Partner-gateway decision was made under (D36).
 *
 * Whether an order went to its Partner's own gateway depends on the Business
 * Account being Non-Conditional **at placement**. That fact is part of the
 * decision and is frozen with it, on the receivable: the account type, the
 * version of the eligibility rule that applied, and the ownership of the payment.
 * The Storefront it belongs to is already there as `website_id`.
 *
 * The table is append-only in the database, so these three can never be changed
 * once written — which is the point. Changing the account's type afterwards moves
 * nothing that was already decided: not the payment's ownership, not the
 * receivable, not the hold. It affects only orders placed after it.
 *
 * Added nullable and unfilled for rows that already exist, because a receivable
 * frozen before this was recorded never had the fact written down and none is
 * guessed. All three are written together or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_platform_receivables
                ADD COLUMN account_type_at_placement varchar(32),
                ADD COLUMN eligibility_version varchar(16),
                ADD COLUMN gateway_ownership varchar(16);

            ALTER TABLE order_platform_receivables
                ADD CONSTRAINT order_platform_receivables_snapshot_is_whole CHECK (
                    (account_type_at_placement IS NULL AND eligibility_version IS NULL AND gateway_ownership IS NULL)
                    OR (account_type_at_placement IS NOT NULL AND eligibility_version IS NOT NULL AND gateway_ownership IS NOT NULL)
                ),
                ADD CONSTRAINT order_platform_receivables_ownership_is_partner CHECK (
                    gateway_ownership IS NULL OR gateway_ownership = 'partner'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_platform_receivables
                DROP CONSTRAINT IF EXISTS order_platform_receivables_ownership_is_partner,
                DROP CONSTRAINT IF EXISTS order_platform_receivables_snapshot_is_whole,
                DROP COLUMN IF EXISTS gateway_ownership,
                DROP COLUMN IF EXISTS eligibility_version,
                DROP COLUMN IF EXISTS account_type_at_placement;
        SQL);
    }
};
