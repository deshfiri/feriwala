<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a never-used Draft product be permanently deleted without losing the
 * evidence that it existed.
 *
 * Every product receives status history when it is created, and that history's
 * foreign key was RESTRICT, so not even a pristine test product could ever be
 * purged. The history is not removed, cascaded away or worked around: each row
 * now carries a snapshot of the product's public id, SKU, name and status, and
 * its `product_id` becomes nullable with ON DELETE SET NULL, so the row outlives
 * the product as a tombstone.
 *
 * The append-only protection stays. The old trigger refused every UPDATE, which
 * would also refuse the UPDATE that `SET NULL` performs, so the update trigger is
 * replaced by one that refuses everything except exactly two things: stamping a
 * still-empty snapshot column, and detaching `product_id` once the snapshot is
 * in place. Rewriting a reason, a status, an actor or a timestamp, re-pointing
 * the row at another product, and every DELETE remain refused.
 *
 * Existing rows are stamped by the application at purge time, through that same
 * permitted UPDATE, rather than backfilled here with the triggers switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_status_history', function (Blueprint $table) {
            $table->string('product_public_id', 26)->nullable()->after('product_id');
            $table->string('product_sku')->nullable()->after('product_public_id');
            $table->string('product_name')->nullable()->after('product_sku');
            $table->string('product_status', 30)->nullable()->after('product_name');
        });

        Schema::table('product_status_history', function (Blueprint $table) {
            $table->dropForeign('product_status_history_product_id_foreign');
        });

        Schema::table('product_status_history', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        Schema::table('product_status_history', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_product_status_history_stamp()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF NEW.product_id IS NOT NULL AND NEW.product_public_id IS NULL THEN
                    SELECT p.public_id, p.sku, p.name, p.status
                      INTO NEW.product_public_id, NEW.product_sku, NEW.product_name, NEW.product_status
                      FROM products p
                     WHERE p.id = NEW.product_id;
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE OR REPLACE FUNCTION feriwala_product_status_history_guard_update()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.axis IS DISTINCT FROM OLD.axis
                    OR NEW.from_status IS DISTINCT FROM OLD.from_status
                    OR NEW.to_status IS DISTINCT FROM OLD.to_status
                    OR NEW.actor_id IS DISTINCT FROM OLD.actor_id
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION
                        '% is append-only (requirements.txt 23.2, 23.3): % is not permitted.',
                        TG_TABLE_NAME, TG_OP
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- A snapshot column may be filled once, never changed.
                IF (OLD.product_public_id IS NOT NULL AND NEW.product_public_id IS DISTINCT FROM OLD.product_public_id)
                    OR (OLD.product_sku IS NOT NULL AND NEW.product_sku IS DISTINCT FROM OLD.product_sku)
                    OR (OLD.product_name IS NOT NULL AND NEW.product_name IS DISTINCT FROM OLD.product_name)
                    OR (OLD.product_status IS NOT NULL AND NEW.product_status IS DISTINCT FROM OLD.product_status)
                THEN
                    RAISE EXCEPTION
                        '% snapshot is append-only (requirements.txt 23.2, 23.3): % is not permitted.',
                        TG_TABLE_NAME, TG_OP
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- A snapshot may only be stamped with what the product says now,
                -- so it cannot be forged while the product still exists.
                IF NEW.product_id IS NOT NULL
                    AND (OLD.product_public_id IS NULL OR OLD.product_sku IS NULL
                         OR OLD.product_name IS NULL OR OLD.product_status IS NULL)
                    AND NOT EXISTS (
                        SELECT 1 FROM products p
                         WHERE p.id = NEW.product_id
                           AND (NEW.product_public_id IS NULL OR p.public_id = NEW.product_public_id)
                           AND (NEW.product_sku IS NULL OR p.sku = NEW.product_sku)
                           AND (NEW.product_name IS NULL OR p.name = NEW.product_name)
                           AND (NEW.product_status IS NULL OR p.status = NEW.product_status)
                    )
                THEN
                    RAISE EXCEPTION
                        '% snapshot must match its product (requirements.txt 23.2, 23.3): % is not permitted.',
                        TG_TABLE_NAME, TG_OP
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- The only move of product_id is detaching it, and only once the
                -- row can still say which product it was about.
                IF NEW.product_id IS DISTINCT FROM OLD.product_id
                    AND (OLD.product_id IS NULL OR NEW.product_id IS NOT NULL OR NEW.product_public_id IS NULL)
                THEN
                    RAISE EXCEPTION
                        '% is append-only (requirements.txt 23.2, 23.3): % is not permitted.',
                        TG_TABLE_NAME, TG_OP
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $function$;

            DROP TRIGGER IF EXISTS product_status_history_no_update ON product_status_history;

            CREATE TRIGGER product_status_history_stamp
                BEFORE INSERT ON product_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_status_history_stamp();

            CREATE TRIGGER product_status_history_guard_update
                BEFORE UPDATE ON product_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_status_history_guard_update();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('product_status_history')->whereNull('product_id')->exists()) {
            throw new RuntimeException(
                'Tombstoned product_status_history rows exist; they cannot be re-attached to a product. Not reversing.'
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_status_history_guard_update ON product_status_history;
            DROP TRIGGER IF EXISTS product_status_history_stamp ON product_status_history;
            DROP FUNCTION IF EXISTS feriwala_product_status_history_guard_update();
            DROP FUNCTION IF EXISTS feriwala_product_status_history_stamp();

            CREATE TRIGGER product_status_history_no_update
                BEFORE UPDATE ON product_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);

        Schema::table('product_status_history', function (Blueprint $table) {
            $table->dropForeign('product_status_history_product_id_foreign');
        });

        Schema::table('product_status_history', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });

        Schema::table('product_status_history', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->dropColumn(['product_public_id', 'product_sku', 'product_name', 'product_status']);
        });
    }
};
