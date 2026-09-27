<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which source is fulfilling one order line, and every source that was chosen
 * for it before (D27 batch, staff source allocation).
 *
 * ### Why this is a table and not more columns on `order_items`
 *
 * `order_items` is immutable by trigger — `order_items_are_snapshots` refuses
 * every UPDATE and DELETE — so the `supplier_*` snapshot columns already on a
 * line are exactly that: a snapshot, written once at placement and never
 * revised. That is the right guarantee for what the customer bought, and the
 * wrong shape for a decision staff are allowed to change: reallocating a line
 * from one Supplier to another, or from a Supplier to the Central Warehouse,
 * cannot rewrite those columns and must not try.
 *
 * So the allocation becomes its own record. Each one is a complete, frozen
 * account of a decision — source, cost, selling price, margin, who chose it and
 * when — and a line's history is the series of them. A partial unique index
 * keeps exactly one **active** at a time, which is the no-two-reservations
 * invariant enforced by the database rather than by the care of the action.
 *
 * The placement-time columns on `order_items` are untouched and keep meaning
 * what they meant: the source resolved when the order was placed. Nothing here
 * reads or contradicts them.
 *
 * ### Warehouse or Supplier, never both
 *
 * `source_type` decides which set of source columns must be filled and which
 * must be null, checked by the database. The reservation underneath is the
 * existing `stock_reservations` row, which already carries `stock_item_id` XOR
 * `supplier_offer_stock_id` — this table does not restate which source that is,
 * it points at the reservation and records the decision around it.
 *
 * ### Money
 *
 * Flat Taka throughout (D26): `numeric(19,2)`, `100` meaning BDT 100.00, no
 * minor units anywhere. `expected_margin` is stored rather than derived so the
 * figure a member of staff was shown when they confirmed is the figure kept,
 * and the database checks it adds up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();

            $table->string('source_type', 24);

            // Central Warehouse source.
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();

            // Supplier Offer source. The price change is the version of the
            // rates that was in force, so a later repricing cannot change what
            // this allocation agreed to pay.
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_offer_price_change_id')->nullable()
                ->constrained('supplier_offer_price_changes')->restrictOnDelete();

            // The reservation holding the stock. Nullable only for the moment
            // between insert and bind inside one transaction.
            $table->foreignId('stock_reservation_id')->nullable()
                ->constrained('stock_reservations')->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // What this source costs Feriwala per unit: the Supplier Rate, or
            // the warehouse's own internal cost.
            $table->decimal('unit_cost', 19, 2);
            // What the line sells for per unit.
            $table->decimal('platform_rate', 19, 2);
            $table->decimal('expected_margin', 19, 2);
            $table->char('currency_code', 3);

            $table->string('status', 16);

            $table->foreignId('allocated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at');
            $table->text('allocation_reason')->nullable();

            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();

            // The allocation that took this one's place, when a line was
            // reallocated. Reads the history forwards without a join on time.
            $table->foreignId('superseded_by_allocation_id')->nullable()
                ->constrained('order_item_allocations')->nullOnDelete();

            $table->string('idempotency_key', 160)->unique();

            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index(['supplier_id', 'status']);
            $table->index(['order_item_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_item_allocations
                ADD CONSTRAINT order_item_allocations_source_type_known CHECK (
                    source_type IN ('warehouse', 'supplier_offer')
                ),
                ADD CONSTRAINT order_item_allocations_status_known CHECK (
                    status IN ('active', 'released', 'superseded', 'cancelled')
                ),

                /*
                 * The source columns and the source type agree. A row claiming
                 * a warehouse while naming a Supplier offer is the ambiguity
                 * that makes a payable go to the wrong party.
                 */
                ADD CONSTRAINT order_item_allocations_source_is_coherent CHECK (
                    (
                        source_type = 'warehouse'
                        AND warehouse_id IS NOT NULL
                        AND supplier_id IS NULL
                        AND supplier_offer_id IS NULL
                        AND supplier_offer_price_change_id IS NULL
                    )
                    OR (
                        source_type = 'supplier_offer'
                        AND warehouse_id IS NULL
                        AND supplier_id IS NOT NULL
                        AND supplier_offer_id IS NOT NULL
                        AND supplier_offer_price_change_id IS NOT NULL
                    )
                ),

                ADD CONSTRAINT order_item_allocations_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_item_allocations_cost_not_negative CHECK (unit_cost >= 0),
                ADD CONSTRAINT order_item_allocations_rate_not_negative CHECK (platform_rate >= 0),
                ADD CONSTRAINT order_item_allocations_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),

                -- Exact flat-Taka arithmetic, held by the database too (D26).
                ADD CONSTRAINT order_item_allocations_margin_adds_up CHECK (
                    expected_margin = (platform_rate - unit_cost) * quantity
                ),

                -- A release is recorded or it did not happen.
                ADD CONSTRAINT order_item_allocations_release_is_recorded CHECK (
                    status = 'active' OR released_at IS NOT NULL
                ),
                ADD CONSTRAINT order_item_allocations_active_is_not_released CHECK (
                    status <> 'active' OR (released_at IS NULL AND superseded_by_allocation_id IS NULL)
                );

            /*
             * **One active source per line.** The no-split rule and the
             * never-two-reservations rule, both as one partial unique index
             * rather than as a check the allocating action remembers to make.
             * Two staff confirming different sources at the same moment: the
             * second insert is refused by the index, not by luck.
             */
            CREATE UNIQUE INDEX order_item_allocations_one_active_per_line
                ON order_item_allocations (order_item_id)
                WHERE status = 'active';

            -- A reservation backs one allocation at a time, for the same reason.
            CREATE UNIQUE INDEX order_item_allocations_one_active_per_reservation
                ON order_item_allocations (stock_reservation_id)
                WHERE status = 'active' AND stock_reservation_id IS NOT NULL;

            /*
             * The allocation belongs to its line, and its line to its order.
             * Without this a correct-looking row can point an order's margin at
             * another order's line.
             */
            CREATE OR REPLACE FUNCTION feriwala_order_item_allocation_matches_line() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM order_items
                    WHERE id = NEW.order_item_id AND order_id = NEW.order_id
                ) THEN
                    RAISE EXCEPTION 'an allocation belongs to a line of its own order'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                -- A Supplier offer allocation names the offer's own Supplier.
                IF NEW.source_type = 'supplier_offer' AND NOT EXISTS (
                    SELECT 1 FROM supplier_offers
                    WHERE id = NEW.supplier_offer_id AND supplier_id = NEW.supplier_id
                ) THEN
                    RAISE EXCEPTION 'an allocation names the Supplier that owns its offer'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_item_allocations_match_their_line
                BEFORE INSERT ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_allocation_matches_line();

            /*
             * The decision is frozen. Only the workflow columns move — the
             * status, who released it and why, and the allocation that
             * superseded it. Everything describing *what was decided* is what a
             * payable is calculated from and an audit is read against, and a
             * rate edited afterwards would silently restate both.
             */
            CREATE TRIGGER order_item_allocations_locked_columns
                BEFORE UPDATE OF
                    public_id, order_id, order_item_id, source_type, warehouse_id, supplier_id,
                    supplier_offer_id, supplier_offer_price_change_id, quantity, unit_cost,
                    platform_rate, expected_margin, currency_code, allocated_by, allocated_at,
                    idempotency_key
                ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_id', 'order_item_id', 'source_type', 'warehouse_id', 'supplier_id',
                    'supplier_offer_id', 'supplier_offer_price_change_id', 'quantity', 'unit_cost',
                    'platform_rate', 'expected_margin', 'currency_code', 'allocated_by', 'allocated_at',
                    'idempotency_key'
                );

            CREATE OR REPLACE FUNCTION feriwala_order_item_allocation_is_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'an allocation is never deleted: it is released, superseded or cancelled'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_item_allocations_never_deleted
                BEFORE DELETE ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_allocation_is_never_deleted();
        SQL);

        /*
         * A payable now answers to an allocation.
         *
         * Placement-time payables (`order_placed`) keep working exactly as they
         * did and keep matching the line's own snapshot; the column is null for
         * them. A payable raised by a staff allocation points at that
         * allocation instead, which is what makes a replacement payable
         * possible after a reallocation the immutable line columns cannot
         * describe.
         */
        Schema::table('supplier_payables', function (Blueprint $table) {
            $table->foreignId('order_item_allocation_id')->nullable()
                ->constrained('order_item_allocations')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            /*
             * `order_item_id` was plain UNIQUE, which made a reallocation
             * impossible: the cancelled payable for the old Supplier held the
             * only slot the line had, so the new Supplier could never be owed
             * anything. One **live** payable per line is the real rule — a
             * cancelled or reversed one is history and must be allowed to sit
             * beside its replacement.
             */
            ALTER TABLE supplier_payables DROP CONSTRAINT supplier_payables_order_item_id_unique;

            CREATE UNIQUE INDEX supplier_payables_one_live_per_line
                ON supplier_payables (order_item_id)
                WHERE status NOT IN ('cancelled', 'reversed');

            -- And one payable per allocation, live or not: an allocation is
            -- decided once, so it is owed for once.
            CREATE UNIQUE INDEX supplier_payables_one_per_allocation
                ON supplier_payables (order_item_allocation_id)
                WHERE order_item_allocation_id IS NOT NULL;

            ALTER TABLE supplier_payables
                DROP CONSTRAINT supplier_payables_event_known;

            ALTER TABLE supplier_payables
                ADD CONSTRAINT supplier_payables_event_known CHECK (
                    triggering_event IN ('order_placed', 'staff_allocation', 'reallocation')
                ),
                -- An allocation-raised payable says which allocation, and a
                -- placement-time one says none. Neither may be mistaken for the
                -- other when a reversal goes looking for what it reverses.
                ADD CONSTRAINT supplier_payables_allocation_matches_event CHECK (
                    (triggering_event = 'order_placed' AND order_item_allocation_id IS NULL)
                    OR (triggering_event IN ('staff_allocation', 'reallocation') AND order_item_allocation_id IS NOT NULL)
                );

            /*
             * The snapshot a payable must agree with is now whichever one
             * decided it: the allocation when there is one, the order line's
             * own placement snapshot when there is not. Same guarantee as
             * before — a payable can never state a Supplier, quantity, rate or
             * currency that its source does not — extended to the source that
             * can change.
             */
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_matches_line() RETURNS trigger AS $$
            BEGIN
                IF NEW.order_item_allocation_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM order_item_allocations
                        WHERE id = NEW.order_item_allocation_id
                          AND order_id = NEW.order_id
                          AND order_item_id = NEW.order_item_id
                          AND source_type = 'supplier_offer'
                          AND supplier_id = NEW.supplier_id
                          AND supplier_offer_id = NEW.supplier_offer_id
                          AND supplier_offer_price_change_id = NEW.supplier_offer_price_change_id
                          AND quantity = NEW.quantity
                          AND unit_cost = NEW.supplier_rate
                          AND currency_code = NEW.currency_code
                    ) THEN
                        RAISE EXCEPTION 'a Supplier payable must match the allocation it was raised for'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM order_items
                    WHERE id = NEW.order_item_id
                      AND order_id = NEW.order_id
                      AND supplier_id = NEW.supplier_id
                      AND supplier_offer_id = NEW.supplier_offer_id
                      AND supplier_offer_price_change_id = NEW.supplier_offer_price_change_id
                      AND supplier_allocated_quantity = NEW.quantity
                      AND supplier_rate = NEW.supplier_rate
                      AND supplier_currency_code = NEW.currency_code
                ) THEN
                    RAISE EXCEPTION 'a Supplier payable must match the allocation snapshot of its order line'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_matches_line() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM order_items
                    WHERE id = NEW.order_item_id
                      AND order_id = NEW.order_id
                      AND supplier_id = NEW.supplier_id
                      AND supplier_offer_id = NEW.supplier_offer_id
                      AND supplier_offer_price_change_id = NEW.supplier_offer_price_change_id
                      AND supplier_allocated_quantity = NEW.quantity
                      AND supplier_rate = NEW.supplier_rate
                      AND supplier_currency_code = NEW.currency_code
                ) THEN
                    RAISE EXCEPTION 'a Supplier payable must match the allocation snapshot of its order line'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE supplier_payables
                DROP CONSTRAINT IF EXISTS supplier_payables_allocation_matches_event,
                DROP CONSTRAINT IF EXISTS supplier_payables_event_known;

            ALTER TABLE supplier_payables
                ADD CONSTRAINT supplier_payables_event_known CHECK (
                    triggering_event IN ('order_placed')
                );

            DROP INDEX IF EXISTS supplier_payables_one_per_allocation;
            DROP INDEX IF EXISTS supplier_payables_one_live_per_line;

            ALTER TABLE supplier_payables
                ADD CONSTRAINT supplier_payables_order_item_id_unique UNIQUE (order_item_id);
        SQL);

        Schema::table('supplier_payables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_allocation_id');
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_item_allocations_never_deleted ON order_item_allocations;
            DROP FUNCTION IF EXISTS feriwala_order_item_allocation_is_never_deleted();
            DROP TRIGGER IF EXISTS order_item_allocations_locked_columns ON order_item_allocations;
            DROP TRIGGER IF EXISTS order_item_allocations_match_their_line ON order_item_allocations;
            DROP FUNCTION IF EXISTS feriwala_order_item_allocation_matches_line();
        SQL);

        Schema::dropIfExists('order_item_allocations');
    }
};
