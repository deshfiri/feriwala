<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ERP wholesale cart (§14, P4-3).
 *
 * **One cart per person, and only ever theirs.** Every read and write of a cart
 * goes through the signed-in user, so there is no cart identifier anybody could
 * change to reach someone else's (§31.3). The cart also names the business
 * account it was filled for, because what may be bought, and at what price, is
 * the account's question (§11.1) — a cart filled for one account is never priced
 * for another.
 *
 * **A cart holds what somebody intends to buy, not what they will pay.** No
 * total, discount, tax or charge is stored: every figure is worked out again on
 * the server each time the cart is shown or checked out (§36.1). The one price
 * kept, `unit_price_seen_minor`, is the unit price the person was last shown —
 * kept only so a price that has changed since can be pointed out to them, never
 * charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->integer('quantity');

            // What the person was last shown, to point out a change — never charged.
            $table->bigInteger('unit_price_seen_minor')->nullable();
            $table->char('currency_code', 3)->default('BDT');

            $table->timestamps();

            $table->index(['cart_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cart_items
                ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT cart_items_unit_price_seen_not_negative CHECK (unit_price_seen_minor IS NULL OR unit_price_seen_minor >= 0);

            -- One line per stockable unit: the product itself, or one variation.
            CREATE UNIQUE INDEX cart_items_product_unique ON cart_items (cart_id, product_id) WHERE product_variant_id IS NULL;
            CREATE UNIQUE INDEX cart_items_variant_unique ON cart_items (cart_id, product_variant_id) WHERE product_variant_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION feriwala_cart_item_variant_matches_product() RETURNS trigger AS $$
            BEGIN
                IF NEW.product_variant_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM product_variants
                    WHERE id = NEW.product_variant_id AND product_id = NEW.product_id
                ) THEN
                    RAISE EXCEPTION 'a cart line''s variation must belong to its product'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cart_items_variant_matches_product
                BEFORE INSERT ON cart_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_cart_item_variant_matches_product();

            -- What a line is for, and whose cart it sits in, never change once written.
            CREATE TRIGGER cart_items_locked_columns
                BEFORE UPDATE OF public_id, cart_id, product_id, product_variant_id ON cart_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'cart_id', 'product_id', 'product_variant_id');

            CREATE TRIGGER carts_locked_columns
                BEFORE UPDATE OF public_id, user_id, business_account_id ON carts
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'user_id', 'business_account_id');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_cart_item_variant_matches_product();');
    }
};
