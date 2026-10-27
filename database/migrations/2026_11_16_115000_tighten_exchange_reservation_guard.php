<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The narrow exception 2026_11_16_110000 made for an exchange line's stock hold,
 * tightened (D35).
 *
 * The history guard lets an exchange line's empty `stock_reservation_id` be filled.
 * This second trigger decides *what* it may be filled with, and runs beside the
 * guard — both must agree. Once a line has its reservation it can be neither
 * cleared nor pointed at another, and the reservation must be the one made for
 * this very line: the same account, the same order reference and line, the same
 * quantity, and stock of the same product and variation. No other reservation
 * can be attached, and no reservation can hold two lines.
 *
 * Every line of every other order stays fully locked by the guard it already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_exchange_line_reservation_is_its_own() RETURNS trigger AS $$
            BEGIN
                IF OLD.stock_reservation_id IS NOT DISTINCT FROM NEW.stock_reservation_id THEN
                    RETURN NEW;
                END IF;

                IF OLD.stock_reservation_id IS NOT NULL OR NEW.stock_reservation_id IS NULL THEN
                    RAISE EXCEPTION 'order_items % already has its stock reservation: it cannot be changed or cleared', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM stock_reservations r
                    JOIN orders o ON o.id = NEW.order_id
                    WHERE r.id = NEW.stock_reservation_id
                        AND o.source = 'exchange'
                        AND r.quantity = NEW.quantity
                        AND r.reference = o.reference || '-L' || NEW.line_number
                        AND r.business_account_id IS NOT DISTINCT FROM o.business_account_id
                        AND (
                            EXISTS (
                                SELECT 1 FROM partner_stock_items p
                                WHERE p.id = r.partner_stock_item_id
                                    AND p.product_id = NEW.product_id
                                    AND p.product_variant_id IS NOT DISTINCT FROM NEW.product_variant_id
                            )
                            OR EXISTS (
                                SELECT 1 FROM stock_items s
                                WHERE s.id = r.stock_item_id
                                    AND s.product_id = NEW.product_id
                                    AND s.product_variant_id IS NOT DISTINCT FROM NEW.product_variant_id
                            )
                        )
                ) OR EXISTS (
                    SELECT 1 FROM order_items other
                    WHERE other.stock_reservation_id = NEW.stock_reservation_id AND other.id <> NEW.id
                ) THEN
                    RAISE EXCEPTION 'order_items %: that reservation was not made for this exchange line', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_items_exchange_reservation_guard
                BEFORE UPDATE OF stock_reservation_id ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_exchange_line_reservation_is_its_own();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_items_exchange_reservation_guard ON order_items;
            DROP FUNCTION IF EXISTS feriwala_exchange_line_reservation_is_its_own();
        SQL);
    }
};
