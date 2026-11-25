<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each unit of a Partner's own stock cost them, first in, first out.
 *
 * Every paid wholesale purchase line becomes one **cost lot**: how many units it
 * gave, what they cost net of discount and excluding tax, and when. A storefront
 * sale takes its units from the oldest lots that still have some, and every
 * lot-and-quantity it took is written down — so a sale's cost is the exact cost
 * of the exact purchases it came from, never an average.
 *
 * Lots move only through `partner_stock_lot_movements`, an append-only record in
 * which each row names the lot, the signed quantity, why, and what caused it;
 * `remaining_quantity` on the lot is that record's running total and nothing
 * else. Every key the movements carry is unique, so a retried sale, release or
 * return can never take or give back the same units twice.
 *
 * Between them, the lots hold exactly the Partner's `available` units: what was
 * bought and not yet set aside for an order.
 */
return new class extends Migration
{
    private const LOT_LOCKED = [
        'public_id', 'business_account_id', 'partner_stock_item_id', 'product_id', 'product_variant_id',
        'source_order_id', 'source_order_item_id', 'provenance', 'purchased_quantity', 'currency_code',
        'net_cost', 'unit_cost', 'cost_is_known', 'purchased_at', 'created_at',
    ];

    public function up(): void
    {
        Schema::create('partner_stock_cost_lots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_stock_item_id')->constrained('partner_stock_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            // The wholesale purchase line these units came from. Null only for
            // units that entered the Partner's stock some other way (a manual
            // adjustment), whose cost is unknown.
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('source_order_item_id')->nullable()->unique()->constrained('order_items')->restrictOnDelete();

            // `purchase` for one purchase line's units (written when it was
            // received, or afterwards for a purchase made before lots existed);
            // `opening` for the units already on the shelf when lots began —
            // costed only when every earlier purchase was at one price;
            // `adjustment` for units with no purchase behind them.
            $table->string('provenance', 16);

            $table->integer('purchased_quantity');
            $table->integer('remaining_quantity');

            // Net of the line's discount, excluding tax: the whole line, exact,
            // and the unit figure it works out to (half away from zero). A sale's
            // cost is worked from the whole-line figure so the units of a lot
            // always add back to exactly what the line cost.
            $table->char('currency_code', 3);
            $table->decimal('net_cost', 19, 2)->nullable();
            $table->decimal('unit_cost', 19, 2)->nullable();
            $table->boolean('cost_is_known');

            // Units ever issued from this lot to a sale, net of none given back:
            // the position each next unit's cost is worked from.
            $table->integer('issued_quantity')->default(0);

            $table->timestamp('purchased_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['partner_stock_item_id', 'purchased_at', 'id']);
        });

        Schema::create('partner_stock_lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_stock_cost_lot_id')->constrained('partner_stock_cost_lots')->restrictOnDelete();
            $table->foreignId('partner_stock_item_id')->constrained('partner_stock_items')->restrictOnDelete();

            // `received`, `allocated` (to a sale), `released` (a sale's hold given
            // back), `returned` (a sold unit back on the shelf), `reversed` (a
            // purchase that stopped counting), `adjusted`.
            $table->string('type', 16);

            // Signed: negative takes units out of the lot, positive puts them back.
            $table->integer('quantity');
            $table->char('currency_code', 3);
            $table->decimal('cost', 19, 2)->nullable();

            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('partner_stock_movement_id')->nullable()->constrained('partner_stock_movements')->restrictOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['stock_reservation_id', 'partner_stock_cost_lot_id']);
            $table->index(['partner_stock_cost_lot_id', 'id']);
        });

        $locked = implode(', ', self::LOT_LOCKED);
        $lockedArguments = implode(', ', array_map(fn (string $column) => "'{$column}'", self::LOT_LOCKED));

        DB::connection()->getPdo()->exec(<<<SQL
            ALTER TABLE partner_stock_cost_lots
                ADD CONSTRAINT partner_stock_cost_lots_provenance_known CHECK (provenance IN ('purchase', 'opening', 'adjustment')),
                ADD CONSTRAINT partner_stock_cost_lots_quantities CHECK (
                    purchased_quantity > 0 AND remaining_quantity >= 0 AND issued_quantity >= 0
                ),
                ADD CONSTRAINT partner_stock_cost_lots_cost_known_means_cost CHECK (
                    cost_is_known = (net_cost IS NOT NULL AND unit_cost IS NOT NULL)
                ),
                ADD CONSTRAINT partner_stock_cost_lots_cost_not_negative CHECK (
                    (net_cost IS NULL OR net_cost >= 0) AND (unit_cost IS NULL OR unit_cost >= 0)
                ),
                ADD CONSTRAINT partner_stock_cost_lots_purchase_has_source CHECK (
                    provenance <> 'purchase' OR source_order_item_id IS NOT NULL
                );

            CREATE TRIGGER partner_stock_cost_lots_locked_columns
                BEFORE UPDATE OF {$locked} ON partner_stock_cost_lots
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$lockedArguments});

            CREATE TRIGGER partner_stock_cost_lots_no_delete
                BEFORE DELETE ON partner_stock_cost_lots
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            ALTER TABLE partner_stock_lot_movements
                ADD CONSTRAINT partner_stock_lot_movements_type_known CHECK (
                    type IN ('received', 'allocated', 'released', 'returned', 'reversed', 'adjusted')
                ),
                ADD CONSTRAINT partner_stock_lot_movements_moves_something CHECK (quantity <> 0),
                ADD CONSTRAINT partner_stock_lot_movements_sign_matches_type CHECK (
                    (type IN ('received', 'released', 'returned') AND quantity > 0)
                    OR (type IN ('allocated', 'reversed') AND quantity < 0)
                    OR type = 'adjusted'
                ),
                ADD CONSTRAINT partner_stock_lot_movements_allocation_names_reservation CHECK (
                    type NOT IN ('allocated', 'released') OR stock_reservation_id IS NOT NULL
                ),
                ADD CONSTRAINT partner_stock_lot_movements_return_names_line CHECK (
                    type <> 'returned' OR order_item_id IS NOT NULL
                );

            CREATE TRIGGER partner_stock_lot_movements_no_update
                BEFORE UPDATE ON partner_stock_lot_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER partner_stock_lot_movements_no_delete
                BEFORE DELETE ON partner_stock_lot_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_stock_lot_movements');
        Schema::dropIfExists('partner_stock_cost_lots');
    }
};
