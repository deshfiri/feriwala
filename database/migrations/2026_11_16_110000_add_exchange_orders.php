<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exchanges (D35): one return case for the old goods and one new operational
 * order for the replacement, each linked to the other and to the order they
 * came from.
 *
 * The exchange order is an order like any other in fulfilment and courier — it
 * has its own lines, its own address snapshot, its own tracking — but it is not
 * a sale: it has no payment of its own, and it never creates a second sale, a
 * second profit or a second supplier payable for goods that were already sold.
 * `source = 'exchange'` is what says so, and what the database holds to.
 *
 * **The original order is never changed.** The replacement is a new order whose
 * lines point back at the lines they replace (`replaces_order_item_id`); nothing
 * on the original is overwritten.
 *
 * Display numbers are `EX-{original number}`, then `-2`, `-3`, … for further
 * exchanges of the same original. They are sequence numbers handed out under a
 * lock on the original order, and **unique in the database** — the application's
 * lock keeps two requests from asking for the same one, and the unique index is
 * what makes it impossible if the lock is ever bypassed. The internal id is
 * separate and never reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A line is a snapshot of what was bought, with one exception: an
        // exchange order's line has no stock held for it until the returned goods
        // are approved, so its empty reservation link may be filled — once, and
        // nothing else on the line may change with it.
        DB::connection()->getPdo()->exec($this->historyRowGuard(withExchangeReservation: true));

        Schema::table('orders', function (Blueprint $table) {
            // The original order an exchange order replaces goods of, and the
            // case that asked for it. Null on every other order.
            $table->foreignId('original_order_id')->nullable()->after('cart_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('exchange_return_id')->nullable()->after('original_order_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // The line of the original order this line replaces.
            $table->foreignId('replaces_order_item_id')->nullable()->after('stock_reservation_id')->constrained('order_items')->restrictOnDelete();
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->foreignId('original_order_id')->nullable()->after('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('exchange_sequence')->nullable()->after('case_type');
            $table->string('exchange_number', 40)->nullable()->after('exchange_sequence');
            $table->foreignId('exchange_order_id')->nullable()->after('exchange_number')->constrained('orders')->restrictOnDelete();
            $table->foreignId('previous_exchange_id')->nullable()->after('exchange_order_id')->constrained('order_returns')->restrictOnDelete();

            // What replacing the goods came to against what the old goods were worth.
            $table->decimal('exchange_additional_due', 19, 2)->nullable();
            $table->decimal('exchange_refund_due', 19, 2)->nullable();
            $table->string('exchange_difference_state', 24)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                DROP CONSTRAINT orders_source_known,
                ADD CONSTRAINT orders_source_known CHECK (
                    source IN ('erp_wholesale', 'website', 'manual', 'api', 'admin', 'external', 'exchange')
                ),
                ADD CONSTRAINT orders_exchange_fields_match_source CHECK (
                    (source = 'exchange') = (original_order_id IS NOT NULL AND exchange_return_id IS NOT NULL)
                );

            ALTER TABLE orders
                ADD CONSTRAINT orders_exchange_return_id_foreign
                FOREIGN KEY (exchange_return_id) REFERENCES order_returns (id) ON DELETE RESTRICT;

            CREATE UNIQUE INDEX orders_one_per_exchange_case ON orders (exchange_return_id) WHERE exchange_return_id IS NOT NULL;

            -- What an exchange order is, and which order it replaces goods of, is
            -- fixed when it is made.
            CREATE TRIGGER orders_exchange_links_locked
                BEFORE UPDATE OF original_order_id, exchange_return_id ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('original_order_id', 'exchange_return_id');

            ALTER TABLE order_returns
                ADD CONSTRAINT order_returns_exchange_sequence_positive CHECK (exchange_sequence IS NULL OR exchange_sequence >= 1),
                -- An exchange has a number, a place in the sequence and an original order;
                -- a plain return has none of them.
                ADD CONSTRAINT order_returns_exchange_fields_match_type CHECK (
                    (case_type = 'exchange') = (exchange_sequence IS NOT NULL AND exchange_number IS NOT NULL AND original_order_id IS NOT NULL)
                ),
                ADD CONSTRAINT order_returns_exchange_difference_known CHECK (
                    exchange_difference_state IS NULL
                    OR exchange_difference_state IN ('not_applicable', 'collection_pending', 'collected', 'refund_due')
                ),
                ADD CONSTRAINT order_returns_exchange_amounts_not_negative CHECK (
                    (exchange_additional_due IS NULL OR exchange_additional_due >= 0)
                    AND (exchange_refund_due IS NULL OR exchange_refund_due >= 0)
                );

            -- The sequence is per original order, and a number is never handed out twice.
            CREATE UNIQUE INDEX order_returns_one_sequence_per_original
                ON order_returns (original_order_id, exchange_sequence) WHERE exchange_sequence IS NOT NULL;
            CREATE UNIQUE INDEX order_returns_exchange_number_unique
                ON order_returns (exchange_number) WHERE exchange_number IS NOT NULL;
            CREATE UNIQUE INDEX order_returns_one_per_exchange_order
                ON order_returns (exchange_order_id) WHERE exchange_order_id IS NOT NULL;

            -- What an exchange *is* cannot be rewritten: its place in the sequence,
            -- its number, the order it is of. Its order and its price difference are
            -- written once, when the exchange order is made.
            CREATE TRIGGER order_returns_exchange_identity_locked
                BEFORE UPDATE OF case_type, original_order_id, exchange_sequence, exchange_number, previous_exchange_id
                ON order_returns
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'case_type', 'original_order_id', 'exchange_sequence', 'exchange_number', 'previous_exchange_id'
                );
        SQL);
    }

    /**
     * `feriwala_history_row_guard()` as 2026_10_31_090000 defined it, with — when
     * asked — one more permitted move on `order_items`: an exchange line's empty
     * `stock_reservation_id` being filled.
     */
    private function historyRowGuard(bool $withExchangeReservation): string
    {
        $exception = $withExchangeReservation ? <<<'SQL'

                        -- An exchange line gets its stock hold once the returned goods are approved.
                        IF TG_TABLE_NAME = 'order_items'
                            AND key = 'stock_reservation_id'
                            AND jsonb_typeof(old_json -> key) = 'null'
                            AND jsonb_typeof(new_json -> key) <> 'null'
                            AND EXISTS (SELECT 1 FROM orders WHERE id = NEW.order_id AND source = 'exchange') THEN
                            permitted := true;
                        END IF;
        SQL : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION feriwala_history_row_guard()
            RETURNS trigger LANGUAGE plpgsql AS \$function\$
            DECLARE
                spec text;
                col text;
                key text;
                permitted boolean;
                old_json jsonb := to_jsonb(OLD);
                new_json jsonb := to_jsonb(NEW);
            BEGIN
                FOR key IN SELECT jsonb_object_keys(new_json) LOOP
                    IF (old_json -> key) IS NOT DISTINCT FROM (new_json -> key) THEN
                        CONTINUE;
                    END IF;

                    permitted := false;
                    {$exception}

                    FOREACH spec IN ARRAY TG_ARGV LOOP
                        col := split_part(spec, ':', 1);

                        -- Filling an empty snapshot with what the product says now.
                        IF key = col || '_snapshot'
                            AND jsonb_typeof(old_json -> key) = 'null'
                            AND (new_json -> key) IS NOT DISTINCT FROM feriwala_snapshot_of(split_part(spec, ':', 2), (new_json ->> col)::bigint) THEN
                            permitted := true;
                        END IF;

                        -- The foreign key's own SET NULL, once the snapshot is in place.
                        IF key = col
                            AND pg_trigger_depth() > 1
                            AND jsonb_typeof(old_json -> key) <> 'null'
                            AND jsonb_typeof(new_json -> key) = 'null'
                            AND jsonb_typeof(new_json -> (col || '_snapshot')) = 'object' THEN
                            permitted := true;
                        END IF;
                    END LOOP;

                    IF NOT permitted THEN
                        RAISE EXCEPTION '% is a snapshot of what happened: changing % is not permitted.', TG_TABLE_NAME, key
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            \$function\$;
        SQL;
    }

    public function down(): void
    {
        DB::connection()->getPdo()->exec($this->historyRowGuard(withExchangeReservation: false));

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_returns_exchange_identity_locked ON order_returns;
            DROP INDEX IF EXISTS order_returns_one_per_exchange_order;
            DROP INDEX IF EXISTS order_returns_exchange_number_unique;
            DROP INDEX IF EXISTS order_returns_one_sequence_per_original;
            ALTER TABLE order_returns
                DROP CONSTRAINT IF EXISTS order_returns_exchange_amounts_not_negative,
                DROP CONSTRAINT IF EXISTS order_returns_exchange_difference_known,
                DROP CONSTRAINT IF EXISTS order_returns_exchange_fields_match_type,
                DROP CONSTRAINT IF EXISTS order_returns_exchange_sequence_positive;
            DROP TRIGGER IF EXISTS orders_exchange_links_locked ON orders;
            DROP INDEX IF EXISTS orders_one_per_exchange_case;
            ALTER TABLE orders
                DROP CONSTRAINT IF EXISTS orders_exchange_return_id_foreign,
                DROP CONSTRAINT IF EXISTS orders_exchange_fields_match_source,
                DROP CONSTRAINT orders_source_known,
                ADD CONSTRAINT orders_source_known CHECK (
                    source IN ('erp_wholesale', 'website', 'manual', 'api', 'admin', 'external')
                );
        SQL);

        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_exchange_id');
            $table->dropConstrainedForeignId('exchange_order_id');
            $table->dropConstrainedForeignId('original_order_id');
            $table->dropColumn(['exchange_sequence', 'exchange_number', 'exchange_additional_due', 'exchange_refund_due', 'exchange_difference_state']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_order_item_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_order_id');
            $table->dropColumn('exchange_return_id');
        });
    }
};
