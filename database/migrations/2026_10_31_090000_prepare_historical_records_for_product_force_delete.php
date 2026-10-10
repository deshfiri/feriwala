<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Super Admin Force Delete a Product without erasing what happened to it.
 *
 * Every historical row that points at a Product or a variation gets an immutable
 * JSON snapshot of what it pointed at (public id, SKU, name, status), stamped on
 * insert by a trigger and backfilled below. Its foreign key then becomes
 * nullable with ON DELETE SET NULL, so the row outlives the Product and a screen
 * reads the snapshot once the relation is null.
 *
 * No protection is switched off. The guards that refused any change to these
 * rows are replaced by ones that permit exactly two moves and nothing else:
 *
 *  - filling a still-empty snapshot column, with what the Product actually says;
 *  - detaching a foreign key to NULL, only from inside the foreign key's own
 *    `SET NULL` action (`pg_trigger_depth() > 1`, never from a plain UPDATE) and
 *    only once the snapshot is in place.
 *
 * Every other update and every delete stays refused on every one of these
 * tables. Ledger, audit and payable tables are untouched.
 *
 * The one DELETE guard that is relaxed is `products_only_draft_deleted`: a
 * Product may be deleted in any status, but only inside a transaction that has
 * named that exact Product in `feriwala.force_delete_product_id` through
 * `set_config(..., true)`, which is what the Force Delete action does after it
 * has authorised, validated and audited the request.
 *
 * Forward-only: once a row has been detached there is nothing to re-attach it to.
 */
return new class extends Migration
{
    /**
     * Table => [foreign key column => referenced table].
     *
     * @var array<string, array<string, string>>
     */
    protected array $references = [
        'stock_items' => ['product_id' => 'products', 'product_variant_id' => 'product_variants'],
        'stock_movements' => ['product_id' => 'products', 'product_variant_id' => 'product_variants'],
        'order_items' => [
            'product_id' => 'products',
            'product_variant_id' => 'product_variants',
            'sourcing_canonical_product_id' => 'products',
            'sourcing_canonical_variant_id' => 'product_variants',
        ],
        'order_item_allocations' => ['source_product_id' => 'products', 'source_product_variant_id' => 'product_variants'],
        'product_source_links' => ['ordered_product_id' => 'products', 'ordered_product_variant_id' => 'product_variants'],
        'product_same_links' => ['product_a_id' => 'products', 'product_b_id' => 'products'],
        'product_link_variant_mappings' => ['variant_a_id' => 'product_variants', 'variant_b_id' => 'product_variants'],
        'product_sourcing_group_products' => ['product_id' => 'products'],
        'product_sourcing_variant_mappings' => [
            'product_id' => 'products',
            'product_variant_id' => 'product_variants',
            'canonical_product_variant_id' => 'product_variants',
        ],
        'supplier_offers' => ['product_id' => 'products', 'product_variant_id' => 'product_variants'],
        'supplier_product_listings' => ['connected_product_id' => 'products'],
        'supplier_product_listing_items' => ['connected_product_variant_id' => 'product_variants'],
        'website_products' => ['product_id' => 'products'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_snapshot_of(referenced text, referenced_id bigint)
            RETURNS jsonb LANGUAGE plpgsql STABLE AS $function$
            DECLARE
                result jsonb;
            BEGIN
                IF referenced_id IS NULL THEN
                    RETURN NULL;
                END IF;

                IF referenced = 'products' THEN
                    SELECT jsonb_build_object('public_id', p.public_id, 'sku', p.sku, 'name', p.name, 'status', p.status)
                      INTO result FROM products p WHERE p.id = referenced_id;
                ELSE
                    SELECT jsonb_build_object('public_id', v.public_id, 'sku', v.sku, 'combination_key', v.combination_key,
                                              'product_public_id', p.public_id, 'product_name', p.name)
                      INTO result FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = referenced_id;
                END IF;

                RETURN result;
            END;
            $function$;

            -- Arguments: 'column:referenced_table', ... Stamps `<column>_snapshot`.
            CREATE OR REPLACE FUNCTION feriwala_stamp_product_snapshots()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            DECLARE
                spec text;
                col text;
                ref text;
                row_json jsonb := to_jsonb(NEW);
            BEGIN
                FOREACH spec IN ARRAY TG_ARGV LOOP
                    col := split_part(spec, ':', 1);
                    ref := split_part(spec, ':', 2);

                    IF jsonb_typeof(row_json -> col) <> 'null'
                        AND jsonb_typeof(row_json -> (col || '_snapshot')) = 'null' THEN
                        NEW := jsonb_populate_record(NEW, jsonb_build_object(
                            col || '_snapshot', feriwala_snapshot_of(ref, (row_json ->> col)::bigint)
                        ));
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $function$;

            -- Arguments: 'column:referenced_table', ... For tables whose rows are
            -- otherwise append-only: only the two permitted moves get through.
            CREATE OR REPLACE FUNCTION feriwala_history_row_guard()
            RETURNS trigger LANGUAGE plpgsql AS $function$
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
            $function$;

            -- The shared "these columns never change" guard, now also letting a
            -- foreign key detach itself through its own SET NULL once its
            -- `<column>_snapshot` is in place.
            CREATE OR REPLACE FUNCTION feriwala_catalogue_columns_are_locked()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            DECLARE
                locked_column text;
                old_json jsonb := to_jsonb(OLD);
                new_json jsonb := to_jsonb(NEW);
            BEGIN
                FOREACH locked_column IN ARRAY TG_ARGV LOOP
                    IF (old_json -> locked_column) IS DISTINCT FROM (new_json -> locked_column) THEN
                        IF pg_trigger_depth() > 1
                            AND jsonb_typeof(old_json -> locked_column) <> 'null'
                            AND jsonb_typeof(new_json -> locked_column) = 'null'
                            AND jsonb_typeof(new_json -> (locked_column || '_snapshot')) = 'object' THEN
                            CONTINUE;
                        END IF;

                        RAISE EXCEPTION '%.% cannot be changed once written', TG_TABLE_NAME, locked_column
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $function$;

            CREATE OR REPLACE FUNCTION feriwala_supplier_listing_connection_is_set_once()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF OLD.connected_product_id IS NOT NULL
                    AND NEW.connected_product_id IS DISTINCT FROM OLD.connected_product_id
                    AND NOT (pg_trigger_depth() > 1
                             AND NEW.connected_product_id IS NULL
                             AND NEW.connected_product_id_snapshot IS NOT NULL) THEN
                    RAISE EXCEPTION 'supplier_product_listings.connected_product_id is set once'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE OR REPLACE FUNCTION feriwala_product_only_draft_is_deleted()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF OLD.status <> 'draft'
                    AND COALESCE(current_setting('feriwala.force_delete_product_id', true), '') <> OLD.id::text THEN
                    RAISE EXCEPTION 'A % product cannot be deleted; archive it instead', OLD.status
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN OLD;
            END;
            $function$;
        SQL);

        // Only what this schema actually has: some of these tables and columns
        // arrived, or were retired, in other migrations.
        foreach ($this->references as $table => $columns) {
            $columns = array_filter($columns, fn (string $referenced, string $column) => Schema::hasTable($table) && Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);

            if ($columns === []) {
                unset($this->references[$table]);

                continue;
            }

            $this->references[$table] = $columns;
        }

        foreach ($this->references as $table => $columns) {
            foreach ($columns as $column => $referenced) {
                $this->detachable($table, $column, $referenced);
            }
        }

        // A detached allocation keeps its match kind; "no source product" now
        // means no snapshot either.
        if (isset($this->references['order_item_allocations']['source_product_id'])) {
            DB::unprepared(<<<'SQL'
                ALTER TABLE order_item_allocations DROP CONSTRAINT IF EXISTS order_item_allocations_source_snapshot_is_coherent;
                ALTER TABLE order_item_allocations
                    ADD CONSTRAINT order_item_allocations_source_snapshot_is_coherent CHECK (
                        (source_product_id IS NULL AND source_product_id_snapshot IS NULL) = (source_match_kind IS NULL)
                        AND (source_product_variant_id IS NULL OR source_product_id IS NOT NULL OR source_product_id_snapshot IS NOT NULL)
                    );
            SQL);
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_items_are_snapshots ON order_items;
            CREATE TRIGGER order_items_no_delete
                BEFORE DELETE ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_is_a_snapshot();

            DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements;
        SQL);

        foreach (['order_items', 'stock_movements'] as $table) {
            // Table and column names come from this class's own constant list.
            // @phpstan-ignore-next-line
            DB::unprepared(sprintf(
                'CREATE TRIGGER %1$s_history_guard BEFORE UPDATE ON %1$s FOR EACH ROW EXECUTE FUNCTION feriwala_history_row_guard(%2$s)',
                $table,
                $this->specList($table),
            ));
        }

        foreach ($this->references as $table => $columns) {
            // @phpstan-ignore-next-line
            DB::unprepared(sprintf(
                'CREATE TRIGGER %1$s_stamp_product_snapshots BEFORE INSERT ON %1$s FOR EACH ROW EXECUTE FUNCTION feriwala_stamp_product_snapshots(%2$s)',
                $table,
                $this->specList($table),
            ));

            // Existing rows, through the same permitted UPDATE the guards allow.
            foreach ($columns as $column => $referenced) {
                // @phpstan-ignore-next-line
                DB::unprepared(sprintf(
                    "UPDATE %1\$s SET %2\$s_snapshot = feriwala_snapshot_of('%3\$s', %2\$s) WHERE %2\$s IS NOT NULL AND %2\$s_snapshot IS NULL",
                    $table,
                    $column,
                    $referenced,
                ));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Force-deleted products leave detached historical rows with nothing to re-attach to; this migration is forward-only.'
        );
    }

    /**
     * Snapshot column, nullable column, and a foreign key that detaches itself.
     */
    protected function detachable(string $table, string $column, string $referenced): void
    {
        DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column}_snapshot jsonb");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");

        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === [$column]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$foreign['name']}");
                DB::statement(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$foreign['name']} FOREIGN KEY ({$column}) REFERENCES {$referenced} (id) ON DELETE SET NULL"
                );
            }
        }
    }

    protected function specList(string $table): string
    {
        return implode(', ', array_map(
            fn (string $column, string $referenced) => "'{$column}:{$referenced}'",
            array_keys($this->references[$table]),
            array_values($this->references[$table]),
        ));
    }
};
