<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Partner profit formula as the owner restated it on 2026-11-28 (D40):
 * net goods selling − the product's wholesale price + the delivery charge.
 *
 * **Two more cost bases** for a profit line: `wholesale_price` (central stock,
 * the wholesale price frozen on the line) and `catalogue_wholesale` (nothing was
 * frozen, so the catalogue's wholesale price). `platform_price` stays allowed so
 * lines worked out before today keep their history.
 *
 * **Top-ups.** A profit credited under the old formula and worth more under the
 * new one is paid the difference as its own Wallet credit. The first credit's
 * columns are locked once posted, so each top-up is an append-only row with its
 * own key, and the profit keeps their running sum in `topped_up_amount` — which
 * a later reversal may also take back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_storefront_profits', function (Blueprint $table) {
            $table->decimal('topped_up_amount', 19, 2)->default(0)->after('released_amount');
        });

        Schema::create('partner_storefront_profit_top_ups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('partner_storefront_profit_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('amount', 19, 2);
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('wallet_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE partner_storefront_profits
                DROP CONSTRAINT partner_storefront_profits_amounts,
                ADD CONSTRAINT partner_storefront_profits_amounts CHECK (
                    released_amount >= 0 AND topped_up_amount >= 0 AND reversed_amount >= 0
                    AND reversed_amount <= released_amount + topped_up_amount
                    AND legacy_credited_amount >= 0
                    AND (collected_amount IS NULL OR collected_amount >= 0)
                ),
                ADD CONSTRAINT partner_storefront_profits_top_up_after_release CHECK (
                    topped_up_amount = 0 OR wallet_transaction_id IS NOT NULL
                );

            ALTER TABLE partner_storefront_profit_lines
                DROP CONSTRAINT partner_storefront_profit_lines_basis_known,
                ADD CONSTRAINT partner_storefront_profit_lines_basis_known CHECK (
                    cost_basis IN ('platform_price', 'wholesale_price', 'catalogue_wholesale', 'fifo_lots', 'historical_single_cost', 'finance_correction')
                );

            ALTER TABLE partner_storefront_profit_top_ups
                ADD CONSTRAINT partner_storefront_profit_top_ups_amount_positive CHECK (amount > 0);

            CREATE TRIGGER partner_storefront_profit_top_ups_no_update
                BEFORE UPDATE ON partner_storefront_profit_top_ups
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER partner_storefront_profit_top_ups_no_delete
                BEFORE DELETE ON partner_storefront_profit_top_ups
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS partner_storefront_profit_top_ups_no_delete ON partner_storefront_profit_top_ups;
            DROP TRIGGER IF EXISTS partner_storefront_profit_top_ups_no_update ON partner_storefront_profit_top_ups;

            ALTER TABLE partner_storefront_profit_lines
                DROP CONSTRAINT partner_storefront_profit_lines_basis_known,
                ADD CONSTRAINT partner_storefront_profit_lines_basis_known CHECK (
                    cost_basis IN ('platform_price', 'fifo_lots', 'historical_single_cost', 'finance_correction')
                );

            ALTER TABLE partner_storefront_profits
                DROP CONSTRAINT partner_storefront_profits_top_up_after_release,
                DROP CONSTRAINT partner_storefront_profits_amounts,
                ADD CONSTRAINT partner_storefront_profits_amounts CHECK (
                    released_amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= released_amount
                    AND legacy_credited_amount >= 0
                    AND (collected_amount IS NULL OR collected_amount >= 0)
                );
        SQL);

        Schema::dropIfExists('partner_storefront_profit_top_ups');

        Schema::table('partner_storefront_profits', function (Blueprint $table) {
            $table->dropColumn('topped_up_amount');
        });
    }
};
