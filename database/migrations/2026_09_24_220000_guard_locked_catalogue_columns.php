<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Locked catalogue information, held by the database (§12, P3-19).
 *
 * The application already refuses these at the policy, the action and the
 * request (P3-15, P3-17). What those layers cannot stop is a write that never
 * passes through them — a tinker session, a future job, a query written in a
 * hurry — so the facts that must never change once a row exists are refused
 * here as well:
 *
 * - **Public identifiers** of every catalogue row. They are what partner
 *   websites, the storefront contract and every URL address a row by; a changed
 *   one silently points somebody's link or order line at nothing.
 * - **A figure's currency.** Minor units mean nothing without it, and a price
 *   whose currency is rewritten is a different price.
 * - **What a variation, file or quantity band belongs to**, and a variation's
 *   combination. A different combination is a different variant (P3-4); moving a
 *   row to another product is moving its SKU, price or picture onto a product
 *   that never had them.
 * - **When a product first went live.** `published_at` is written once, by the
 *   first activation, and never moved or cleared (P3-8).
 * - **Only a draft is removed.** Anything further along is archived (P3-3); a
 *   live product deleted from under its partners is the catalogue they sell
 *   from changing without a record.
 *
 * Each trigger fires only when a statement names a guarded column, and compares
 * values, so writing a column back with the value it already holds is allowed.
 */
return new class extends Migration
{
    /**
     * The columns fixed once a row exists, per table.
     *
     * @var array<string, array<int, string>>
     */
    private const LOCKED = [
        'products' => ['public_id', 'currency_code'],
        'product_variants' => ['public_id', 'product_id', 'combination_key', 'currency_code'],
        'product_media' => ['public_id', 'product_id'],
        'product_price_tiers' => ['product_id', 'product_variant_id', 'currency_code'],
        'categories' => ['public_id'],
        'brands' => ['public_id'],
        'product_attributes' => ['public_id'],
        'product_attribute_values' => ['public_id', 'product_attribute_id'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_catalogue_columns_are_locked() RETURNS trigger AS $$
            DECLARE
                locked_column text;
            BEGIN
                FOREACH locked_column IN ARRAY TG_ARGV LOOP
                    IF (to_jsonb(OLD) -> locked_column) IS DISTINCT FROM (to_jsonb(NEW) -> locked_column) THEN
                        RAISE EXCEPTION '%.% cannot be changed once written', TG_TABLE_NAME, locked_column
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION feriwala_product_published_at_is_set_once() RETURNS trigger AS $$
            BEGIN
                IF OLD.published_at IS NOT NULL AND NEW.published_at IS DISTINCT FROM OLD.published_at THEN
                    RAISE EXCEPTION 'products.published_at is set once, when the product first goes live'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION feriwala_product_only_draft_is_deleted() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'A % product cannot be deleted; archive it instead', OLD.status
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER products_published_at_set_once
                BEFORE UPDATE OF published_at ON products
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_published_at_is_set_once();

            CREATE TRIGGER products_only_draft_deleted
                BEFORE DELETE ON products
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_only_draft_is_deleted();
        SQL);

        foreach (self::LOCKED as $table => $columns) {
            $list = implode(', ', $columns);
            $arguments = implode(', ', array_map(fn (string $column) => "'{$column}'", $columns));

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_locked_columns
                    BEFORE UPDATE OF {$list} ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$arguments});
            SQL);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::LOCKED) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_locked_columns ON {$table};");
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS products_published_at_set_once ON products;
            DROP TRIGGER IF EXISTS products_only_draft_deleted ON products;
            DROP FUNCTION IF EXISTS feriwala_catalogue_columns_are_locked();
            DROP FUNCTION IF EXISTS feriwala_product_published_at_is_set_once();
            DROP FUNCTION IF EXISTS feriwala_product_only_draft_is_deleted();
        SQL);
    }
};
