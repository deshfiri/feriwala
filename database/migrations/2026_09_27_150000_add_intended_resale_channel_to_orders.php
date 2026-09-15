<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a wholesale buyer says they mean to sell (D21, P4-14).
 *
 * **Reporting only, and optional.** Recorded when the order is placed and never
 * changed afterwards, like the rest of what the buyer told us at checkout — so it
 * joins the columns the database refuses to rewrite. Nothing prices, reserves,
 * allows or refuses anything by it.
 */
return new class extends Migration
{
    private const LOCKED_BEFORE = [
        'public_id', 'reference', 'source', 'business_account_id', 'website_id', 'payment_id',
        'idempotency_key', 'checkout_fingerprint', 'customer', 'billing_address', 'shipping_address',
        'currency_code', 'subtotal_minor', 'discount_minor', 'delivery_minor', 'tax_minor',
        'tax_included_minor', 'cod_fee_minor', 'total_minor', 'coupon_code', 'placed_at',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('intended_resale_channel', 32)->nullable()->after('customer_note');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_intended_resale_channel_known CHECK (
                    intended_resale_channel IS NULL
                    OR intended_resale_channel IN ('own_website', 'social_media', 'marketplace', 'physical_shop', 'wholesale', 'other')
                );
            SQL);

        $this->lockColumns([...self::LOCKED_BEFORE, 'intended_resale_channel']);
    }

    public function down(): void
    {
        $this->lockColumns(self::LOCKED_BEFORE);

        DB::unprepared('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_intended_resale_channel_known');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('intended_resale_channel');
        });
    }

    /**
     * @param  list<literal-string>  $columns
     */
    private function lockColumns(array $columns): void
    {
        $locked = implode(', ', $columns);
        $arguments = implode(', ', array_map(fn (string $column) => "'{$column}'", $columns));

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS orders_locked_columns ON orders;

            CREATE TRIGGER orders_locked_columns
                BEFORE UPDATE OF {$locked} ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$arguments});
            SQL);
    }
};
