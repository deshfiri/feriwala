<?php

use App\Domain\Order\Actions\AllocateOrderLineSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets one order line hold more than one simultaneously active allocation —
 * a genuine split across sources (Advanced Order Management batch,
 * Commit 3), not possible under the original "one active allocation per
 * line" rule {@see AllocateOrderLineSource}'s own
 * docblock used to call "the no-split rule."
 *
 * The partial unique index enforcing that rule is replaced by a trigger that
 * enforces the real invariant instead: the **sum** of every active
 * allocation's quantity for a line may never exceed the line's own
 * `quantity`. A single allocation still may not exceed it either, since that
 * is the `sum` with nothing else active. `order_item_allocations_one_active_per_reservation`
 * is untouched — a `ready_stock` allocation still holds exactly one
 * reservation, split or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS order_item_allocations_one_active_per_line;

            CREATE OR REPLACE FUNCTION feriwala_order_item_allocation_quantity_within_line() RETURNS trigger AS $$
            DECLARE
                line_quantity integer;
                other_active_quantity integer;
            BEGIN
                IF NEW.status <> 'active' THEN
                    RETURN NEW;
                END IF;

                SELECT quantity INTO line_quantity FROM order_items WHERE id = NEW.order_item_id;

                SELECT COALESCE(SUM(quantity), 0) INTO other_active_quantity
                FROM order_item_allocations
                WHERE order_item_id = NEW.order_item_id
                    AND status = 'active'
                    AND id <> COALESCE(NEW.id, 0);

                IF other_active_quantity + NEW.quantity > line_quantity THEN
                    RAISE EXCEPTION 'order item % cannot hold % active allocation units (% already active, % requested): the line only has %',
                        NEW.order_item_id, other_active_quantity + NEW.quantity, other_active_quantity, NEW.quantity, line_quantity
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_item_allocations_quantity_within_line
                BEFORE INSERT OR UPDATE OF status ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_allocation_quantity_within_line();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_item_allocations_quantity_within_line ON order_item_allocations;
            DROP FUNCTION IF EXISTS feriwala_order_item_allocation_quantity_within_line();

            CREATE UNIQUE INDEX order_item_allocations_one_active_per_line
                ON order_item_allocations (order_item_id)
                WHERE status = 'active';
        SQL);
    }
};
